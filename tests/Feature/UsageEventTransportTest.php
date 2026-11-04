<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\UsageEvent;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function usageEvent(string $id = 'sample-1', string $meter = 'compute.cpu_ms'): UsageEvent
{
    return new UsageEvent($id, $meter, 1200, new DateTimeImmutable('2026-11-04T10:00:00.123456+01:00'), 'stache', ['project' => 'site-1', 'environment' => 'env-1'], 'workload-1');
}

function usageEventClient(): BillingManagement
{
    return new BillingManagement(new HttpManagementTransport(app(HttpFactory::class), 'https://billing.test', 'token'));
}

it('sends stable event identity time quantity and dimensions through the management client', function (): void {
    Http::fake(['billing.test/api/v1/events' => Http::response(['ok' => true, 'accepted' => 1, 'duplicates' => 0, 'rejected' => []])]);
    $receipt = usageEventClient()->ingestUsageEvents('org-1', [usageEvent()]);
    expect($receipt->accepted)->toBe(1)->and($receipt->duplicates)->toBe(0)->and($receipt->rejected)->toBe([]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer token')
        && $request->data() === ['org' => 'org-1', 'events' => [usageEvent()->toArray()]]);
    expect(usageEvent()->toArray()['occurred_at'])->toBe('2026-11-04T10:00:00.123456+01:00');
});

it('preserves partial rejection identities and future server reason codes', function (): void {
    $body = ['ok' => true, 'accepted' => 1, 'duplicates' => 1, 'rejected' => [['index' => 2, 'id' => 'c', 'reason' => 'future_reason']]];
    Http::fake(['*' => Http::response($body)]);
    $receipt = usageEventClient()->ingestUsageEvents('org-1', [usageEvent('a'), usageEvent('b'), usageEvent('c')]);
    expect($receipt->toArray())->toBe($body)->and($receipt->rejected[0]->id)->toBe('c');
});

it('does not acknowledge an inconsistent receipt', function (array $body): void {
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => usageEventClient()->ingestUsageEvents('org-1', [usageEvent('a'), usageEvent('b')]))->toThrow(BillingUnavailable::class);
})->with([
    'missing status' => [['accepted' => 2, 'duplicates' => 0, 'rejected' => []]],
    'false status' => [['ok' => false, 'accepted' => 2, 'duplicates' => 0, 'rejected' => []]],
    'string count' => [['ok' => true, 'accepted' => '2', 'duplicates' => 0, 'rejected' => []]],
    'negative count' => [['ok' => true, 'accepted' => -1, 'duplicates' => 3, 'rejected' => []]],
    'missing event' => [['ok' => true, 'accepted' => 1, 'duplicates' => 0, 'rejected' => []]],
    'excess count' => [['ok' => true, 'accepted' => 3, 'duplicates' => 0, 'rejected' => []]],
    'missing rejections' => [['ok' => true, 'accepted' => 2, 'duplicates' => 0]],
    'rejected scalar' => [['ok' => true, 'accepted' => 1, 'duplicates' => 0, 'rejected' => ['bad']]],
    'wrong id' => [['ok' => true, 'accepted' => 1, 'duplicates' => 0, 'rejected' => [['index' => 0, 'id' => 'b', 'reason' => 'unknown_meter']]]],
    'out of bounds' => [['ok' => true, 'accepted' => 1, 'duplicates' => 0, 'rejected' => [['index' => 2, 'id' => 'a', 'reason' => 'unknown_meter']]]],
    'missing reason' => [['ok' => true, 'accepted' => 1, 'duplicates' => 0, 'rejected' => [['index' => 0, 'id' => 'a']]]],
    'repeated index' => [['ok' => true, 'accepted' => 0, 'duplicates' => 0, 'rejected' => [['index' => 0, 'id' => 'a', 'reason' => 'unknown_meter'], ['index' => 0, 'id' => 'a', 'reason' => 'unknown_meter']]]],
]);

it('keeps refused and unavailable delivery distinct', function (int $status, string $exception): void {
    Http::fake(['*' => Http::response(['error' => 'unavailable'], $status)]);
    expect(fn () => usageEventClient()->ingestUsageEvents('org-1', [usageEvent()]))->toThrow($exception);
})->with([[401, BillingRefused::class], [422, BillingRefused::class], [429, BillingUnavailable::class], [500, BillingUnavailable::class]]);

it('rejects invalid batches before network access', function (string $org, array $events): void {
    Http::fake();
    expect(fn () => usageEventClient()->ingestUsageEvents($org, $events))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'no org' => fn () => ['', [usageEvent()]],
    'no events' => fn () => ['org-1', []],
    'duplicate ids' => fn () => ['org-1', [usageEvent(), usageEvent()]],
    'nonlist' => fn () => ['org-1', [1 => usageEvent()]],
    'oversize' => fn () => ['org-1', array_map(fn ($i) => usageEvent((string) $i), range(1, 1001))],
]);

it('models partial delivery replay and tenant isolation using the shared fake', function (): void {
    $fake = (new FakeManagementTransport)->withUsageEventMeter('compute.cpu_ms');
    $client = new BillingManagement($fake);
    $events = [usageEvent('a'), usageEvent('b', 'missing')];
    $first = $client->ingestUsageEvents('org-a', $events);
    expect($first->accepted)->toBe(1)->and($first->rejected[0]->reason)->toBe('unknown_meter');
    $retry = $client->ingestUsageEvents('org-a', $events);
    expect($retry->accepted)->toBe(0)->and($retry->duplicates)->toBe(1)->and($retry->rejected)->toHaveCount(1);
    expect($fake->recordedUsageEvents('org-a'))->toBe([usageEvent('a')->toArray()]);
    expect($client->ingestUsageEvents('org-b', [usageEvent('a')])->accepted)->toBe(1);
    $fake->withUsageEventMeter('missing');
    expect($client->ingestUsageEvents('org-a', [usageEvent('b', 'missing')])->accepted)->toBe(1);
});

it('preserves a zero level for time weighted meters', function (): void {
    $event = new UsageEvent('stop', 'memory', 0, new DateTimeImmutable, 'stache', uniqueKey: 'pod-1');
    expect($event->toArray()['value'])->toBe(0)->and($event->toArray()['unique_key'])->toBe('pod-1');
});

it('validates event fields without allowing a repeated identifier to bypass its length limit', function (): void {
    $long = str_repeat('a', 129);
    expect(fn () => new UsageEvent($long, $long, 1, new DateTimeImmutable, $long))->toThrow(InvalidArgumentException::class);
    expect(fn () => new UsageEvent('a', 'cpu', -1, new DateTimeImmutable, 'stache'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new UsageEvent('a', 'cpu', 1, new DateTimeImmutable, 'stache', ['Project' => 'a']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new UsageEvent('a', 'cpu', 1, new DateTimeImmutable, 'stache', ['project' => str_repeat('x', 129)]))->toThrow(InvalidArgumentException::class);
});

it('reports an unsupported custom transport instead of silently discarding events', function (): void {
    $old = Mockery::mock(ManagementTransport::class);
    expect(fn () => (new BillingManagement($old))->ingestUsageEvents('org-a', [usageEvent()]))
        ->toThrow(UnsupportedOperation::class);
});

it('leaves no events stored when a fake batch cannot parse', function (): void {
    $fake = (new FakeManagementTransport)->withUsageEventMeter('compute.cpu_ms');
    $response = $fake->respond('POST', '/api/v1/events', ['org' => 'org-a', 'events' => [usageEvent()->toArray(), [0 => 'bad']]]);
    expect($response->status)->toBe(422)->and($fake->recordedUsageEvents('org-a'))->toBe([]);
});
