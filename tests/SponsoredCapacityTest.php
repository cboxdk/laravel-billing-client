<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\SponsoredCapacityTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpSponsoredCapacityTransport;
use Cbox\Billing\Client\ValueObjects\CapacityAllocation;
use Cbox\Billing\Client\ValueObjects\CapacityClaim;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function capacityResponse(string $claimState = 'reserved', string $allocationState = 'reserved'): array
{
    return ['object' => 'capacity_claim', 'agreement' => 'pool', 'key' => 'attempt', 'state' => $claimState, 'allocation' => [
        'object' => 'capacity_allocation', 'agreement' => 'pool', 'key' => 'generation', 'org' => 'canonical-org',
        'estimate' => 37, 'actual' => in_array($allocationState, ['committed', 'released'], true) ? 29 : null,
        'state' => $allocationState, 'deletion' => $allocationState === 'released' ? 'deletion-1' : null,
    ]];
}

beforeEach(function (): void {
    config()->set('billing-client.api_url', 'https://billing.test/v1');
    config()->set('billing-client.api_token', 'provider-secret');
});

it('transports durable operations and preserves released generations on replay', function (): void {
    Http::fakeSequence()
        ->push(capacityResponse())
        ->push(capacityResponse())
        ->push(capacityResponse('committed', 'committed'))
        ->push(capacityResponse('canceled', 'committed'))
        ->push(capacityResponse('committed', 'released')['allocation'])
        ->push(capacityResponse('committed', 'released')['allocation'])
        ->push(capacityResponse('committed', 'released'));
    $client = app(SponsoredCapacityTransport::class);
    expect($client)->toBeInstanceOf(HttpSponsoredCapacityTransport::class);
    $reserved = $client->reserve('pool', 'attempt', 'external-org', 'generation', 37);
    expect($reserved->allocation->org)->toBe('canonical-org')->and($reserved->allocation->actual)->toBeNull();
    expect($client->claim('pool', 'attempt')->state)->toBe('reserved');
    expect($client->commit('pool', 'attempt', 29)->allocation->actual)->toBe(29);
    expect($client->cancel('pool', 'attempt')->allocation->state)->toBe('committed');
    expect($client->release('pool', 'generation', 'deletion-1')->deletion)->toBe('deletion-1');
    expect($client->allocation('pool', 'generation')->state)->toBe('released');
    expect($client->reserve('pool', 'attempt', 'external-org', 'generation', 37)->allocation->state)->toBe('released');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && $r->url() === 'https://billing.test/v1/capacity-agreements/pool/claims/attempt'
        && $r->hasHeader('Authorization', 'Bearer provider-secret')
        && ! $r->hasHeader('Idempotency-Key')
        && $r->data() === ['org' => 'external-org', 'allocation' => 'generation', 'amount' => 37]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/commit') && $r->data() === ['actual' => 29]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/release') && $r->data() === ['deletion' => 'deletion-1']);
    Http::assertSentCount(7);
});

it('keeps exhaustion distinct from unavailable and never retries an ambiguous write', function (): void {
    Http::fakeSequence()->push(['error' => 'consumer_capacity_exhausted', 'message' => 'Full.'], 409)
        ->push(['error' => 'consumer_capacity_unavailable', 'message' => 'Unavailable.'], 503)
        ->pushFailedConnection('timeout')
        ->push(capacityResponse('canceled', 'canceled'));
    $client = app(SponsoredCapacityTransport::class);
    try {
        $client->reserve('pool', 'attempt', 'org', 'generation', 37);
        $this->fail('Expected exhaustion.');
    } catch (BillingRefused $error) {
        expect($error->status)->toBe(409)->and(json_decode($error->body, true)['error'])->toBe('consumer_capacity_exhausted');
    }
    expect(fn () => $client->reserve('pool', 'attempt', 'org', 'generation', 37))->toThrow(BillingUnavailable::class);
    Http::assertSentCount(2);
    expect(fn () => $client->reserve('pool', 'attempt', 'org', 'generation', 37))->toThrow(BillingUnavailable::class);
    expect($client->reserve('pool', 'attempt', 'org', 'generation', 37)->state)->toBe('canceled');
});

it('fails closed on malformed, mismatched and unsafe successful replies', function (array $response): void {
    Http::fake(['*' => Http::response($response)]);
    expect(fn () => app(SponsoredCapacityTransport::class)->reserve('pool', 'attempt', 'org', 'generation', 37))
        ->toThrow(BillingUnavailable::class);
})->with([
    'wrapper' => [['data' => capacityResponse()]],
    'wrong agreement' => [array_replace(capacityResponse(), ['agreement' => 'other'])],
    'wrong claim' => [array_replace(capacityResponse(), ['key' => 'other'])],
    'wrong allocation' => [array_replace_recursive(capacityResponse(), ['allocation' => ['key' => 'other']])],
    'wrong estimate' => [array_replace_recursive(capacityResponse(), ['allocation' => ['estimate' => 38]])],
    'numeric string' => [array_replace_recursive(capacityResponse(), ['allocation' => ['estimate' => '37']])],
    'unsafe integer' => [array_replace_recursive(capacityResponse(), ['allocation' => ['estimate' => 9007199254740992]])],
    'inconsistent state' => [capacityResponse('committed', 'reserved')],
    'unknown state' => [array_replace(capacityResponse(), ['state' => 'approved'])],
    'missing actual' => [array_replace(capacityResponse(), ['allocation' => array_diff_key(capacityResponse()['allocation'], ['actual' => true])])],
]);

it('rejects a successful response that did not apply the requested transition', function (): void {
    Http::fakeSequence()->push(capacityResponse('committed', 'committed'))->push(capacityResponse())
        ->push(capacityResponse('committed', 'committed')['allocation']);
    $client = app(SponsoredCapacityTransport::class);
    expect(fn () => $client->commit('pool', 'attempt', 28))->toThrow(BillingUnavailable::class);
    expect(fn () => $client->cancel('pool', 'attempt'))->toThrow(BillingUnavailable::class);
    expect(fn () => $client->release('pool', 'generation', 'deletion-1'))->toThrow(BillingUnavailable::class);
});

it('validates safe integer boundaries and path keys before sending', function (): void {
    $response = array_replace_recursive(capacityResponse(), ['allocation' => ['estimate' => CapacityAllocation::MAX_AMOUNT]]);
    Http::fakeSequence()->push($response)->push(array_replace_recursive($response, ['state' => 'committed', 'allocation' => ['state' => 'committed', 'actual' => 0]]));
    $client = app(SponsoredCapacityTransport::class);
    foreach ([0, -1, CapacityAllocation::MAX_AMOUNT + 1] as $amount) {
        expect(fn () => $client->reserve('pool', 'attempt', 'org', 'generation', $amount))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $client->claim('pool', '../other'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
    expect($client->reserve('pool', 'attempt', 'org', 'generation', CapacityAllocation::MAX_AMOUNT)->allocation->estimate)->toBe(CapacityAllocation::MAX_AMOUNT);
    expect($client->commit('pool', 'attempt', 0)->allocation->actual)->toBe(0);
});

it('does not follow redirects or send an unconfigured credential', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://other.test'])]);
    expect(fn () => app(SponsoredCapacityTransport::class)->claim('pool', 'attempt'))->toThrow(BillingUnavailable::class);
    Http::assertSentCount(1);
    app()->forgetInstance(SponsoredCapacityTransport::class);
    config()->set('billing-client.api_token', '');
    expect(fn () => app(SponsoredCapacityTransport::class)->claim('pool', 'attempt'))->toThrow(BillingRefused::class);
    Http::assertSentCount(1);
});

it('scripts reconciliation with the shared fake and refuses unconfigured success', function (): void {
    $fake = $this->capacityTransport();
    app()->instance(SponsoredCapacityTransport::class, $fake);
    $fake->responses = [new BillingUnavailable('timeout'), CapacityClaim::fromArray(capacityResponse('committed', 'released'))];
    $client = app(SponsoredCapacityTransport::class);
    expect(fn () => $client->reserve('pool', 'attempt', 'org', 'generation', 37))->toThrow(BillingUnavailable::class);
    expect($client->claim('pool', 'attempt')->allocation->state)->toBe('released');
    expect($fake->calls)->toHaveCount(2);
    expect(fn () => $client->cancel('pool', 'attempt'))->toThrow(BillingUnavailable::class);
});
