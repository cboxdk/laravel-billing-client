<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\LimitIncreaseInput;
use Cbox\Billing\Client\ValueObjects\ResourceLimit;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function limitClient(): BillingManagement
{
    return new BillingManagement(new HttpManagementTransport(app(Factory::class), 'https://billing.test/api/v1', 'limit-token'));
}

function limitWire(): array
{
    return ['scope' => 'organization', 'monetary_exposure_included' => false, 'limits' => [
        'max_sites' => ['key' => 'max_sites', 'enabled' => true, 'limit' => 20, 'base_limit' => 10,
            'grant_request_id' => 'lir_5Rk8wTq3NvX7mB2hYc9LzF', 'source' => 'approved_request', 'expires_at' => '2026-12-06T12:00:00Z', 'observed_at' => '2026-11-06T12:00:00Z'],
    ]];
}

function increaseWire(): array
{
    return ['id' => 'lir_5Rk8wTq3NvX7mB2hYc9LzF', 'request_key' => '0d01641e-ff77-4465-9ba9-20e372bd5670', 'feature' => 'max_sites', 'status' => 'pending',
        'observed_limit' => 10, 'requested_limit' => 30, 'reason' => 'More sites', 'approved_limit' => null, 'decision_reason' => null,
        'decided_at' => null, 'expires_at' => null, 'revoked_at' => null, 'revocation_reason' => null, 'created_at' => '2026-11-06T12:00:00Z'];
}

it('reads scoped limits and makes stale or expired evidence unknown', function (): void {
    Http::preventStrayRequests();
    Http::fake(['billing.test/api/v1/organizations/org%2Fa/limits?product=hosting' => Http::response(limitWire())]);
    $limit = limitClient()->resourceLimits('org/a', 'hosting')['max_sites'];
    expect($limit->limit)->toBe(20)->and($limit->baseLimit)->toBe(10)
        ->and($limit->ceilingAt(new DateTimeImmutable('2026-11-06T12:00:30Z'), 30))->toBe(20)
        ->and($limit->ceilingAt(new DateTimeImmutable('2026-11-06T12:00:31Z'), 30))->toBeNull()
        ->and($limit->ceilingAt(new DateTimeImmutable('2026-12-06T12:00:00Z'), 99999999))->toBeNull()
        ->and($limit->ceilingAt(new DateTimeImmutable('2026-11-06T11:59:59Z'), 30))->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer limit-token'));
});

it('rejects missing wrong typed and inconsistent limit facts', function (string $field, mixed $value): void {
    $wire = limitWire();
    $wire['limits']['max_sites'][$field] = $value;
    Http::fake(['billing.test/*' => Http::response($wire)]);
    expect(fn () => limitClient()->resourceLimits('a'))->toThrow(BillingUnavailable::class);
})->with([
    ['limit', '20'], ['limit', -1], ['limit', 9.5], ['enabled', 1], ['key', 'another'], ['source', 'unknown'],
    ['grant_request_id', null], ['expires_at', 'tomorrow'], ['observed_at', '2026-02-30T12:00:00Z'], ['expires_at', '2026-11-05T00:00:00Z'],
]);

it('rejects monetary or unscoped replies rather than inventing capacity', function (): void {
    Http::fake(['billing.test/*' => Http::response(['limits' => []])]);
    expect(fn () => limitClient()->resourceLimits('a'))->toThrow(BillingUnavailable::class);
});

it('submits the durable key and verifies the receipt identifies the same request', function (): void {
    Http::preventStrayRequests();
    Http::fake(['billing.test/api/v1/organizations/a/limit-increase-requests?product=hosting' => Http::response(increaseWire(), 201)]);
    $input = new LimitIncreaseInput(increaseWire()['request_key'], 'max_sites', 30, 'More sites');
    $receipt = limitClient()->requestLimitIncrease('a', $input, 'hosting');
    expect($receipt->id)->toBe('lir_5Rk8wTq3NvX7mB2hYc9LzF')->and($receipt->status)->toBe('pending');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === $input->toArray());
    Http::fake(['billing.test/*' => Http::response([...increaseWire(), 'requested_limit' => 40])]);
    expect(fn () => limitClient()->requestLimitIncrease('a', $input))->toThrow(BillingUnavailable::class);
});

it('paginates requests and verifies cancellation receipts', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'billing.test/api/v1/organizations/a/limit-increase-requests?page=2' => Http::response(['data' => [increaseWire()], 'current_page' => 2, 'last_page' => 2]),
        'billing.test/api/v1/organizations/a/limit-increase-requests/lir_5Rk8wTq3NvX7mB2hYc9LzF/cancel' => Http::sequence()->push([...increaseWire(), 'status' => 'cancelled'])->push([...increaseWire(), 'id' => 'lir_9Qw2eRt5yU8iO1pA4sD7fG', 'status' => 'cancelled']),
    ]);
    $page = limitClient()->limitIncreaseRequests('a', 2);
    expect($page->data[0]->id)->toBe('lir_5Rk8wTq3NvX7mB2hYc9LzF')->and($page->currentPage)->toBe(2)
        ->and(limitClient()->cancelLimitIncrease('a', 'lir_5Rk8wTq3NvX7mB2hYc9LzF')->status)->toBe('cancelled');
    expect(fn () => limitClient()->cancelLimitIncrease('a', 'lir_5Rk8wTq3NvX7mB2hYc9LzF'))->toThrow(BillingUnavailable::class);
});

it('preserves unavailable and refused responses', function (int $status, string $exception): void {
    Http::fake(['billing.test/*' => Http::response(['error' => 'unavailable'], $status)]);
    expect(fn () => limitClient()->resourceLimits('a'))->toThrow($exception);
})->with([[403, BillingRefused::class], [404, BillingRefused::class], [422, BillingRefused::class], [429, BillingUnavailable::class], [503, BillingUnavailable::class]]);

it('rejects invalid inputs before sending a request', function (): void {
    Http::fake();
    expect(fn () => new LimitIncreaseInput('invalid', 'sites', 30, 'Reason'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new LimitIncreaseInput(increaseWire()['request_key'], 'sites', 0, 'Reason'))->toThrow(InvalidArgumentException::class);
    expect(fn () => limitClient()->resourceLimits(''))->toThrow(InvalidArgumentException::class);
    expect(fn () => limitClient()->cancelLimitIncrease('a', ' '))->toThrow(InvalidArgumentException::class);
    expect(fn () => limitClient()->limitIncreaseRequests('a', 0))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('dogfoods the shared fake with replay cancellation tenant boundaries and outages', function (): void {
    $fake = (new FakeManagementTransport)->withResourceLimits('a', [new ResourceLimit('max_sites', true, 10, 10)]);
    $client = new BillingManagement($fake);
    $input = new LimitIncreaseInput(increaseWire()['request_key'], 'max_sites', 30, 'More sites');
    $first = $client->requestLimitIncrease('a', $input);
    expect($client->requestLimitIncrease('a', $input)->id)->toBe($first->id)
        ->and($client->resourceLimits('a')['max_sites']->limit)->toBe(10)
        ->and($client->limitIncreaseRequests('a')->data)->toHaveCount(1);
    expect(fn () => $client->cancelLimitIncrease('other', $first->id))->toThrow(BillingRefused::class);
    expect($client->cancelLimitIncrease('a', $first->id)->status)->toBe('cancelled');
    $fake->takeDown();
    expect(fn () => $client->resourceLimits('a'))->toThrow(BillingUnavailable::class);
    $fake->bringUp()->refuseWith(403);
    expect(fn () => $client->limitIncreaseRequests('a'))->toThrow(BillingRefused::class);
});

it('does not break custom transports that predate resource limits', function (): void {
    $old = Mockery::mock(ManagementTransport::class);
    expect(fn () => (new BillingManagement($old))->resourceLimits('a'))->toThrow(UnsupportedOperation::class);
});
