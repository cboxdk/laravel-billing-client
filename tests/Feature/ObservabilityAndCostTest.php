<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;

it('emits allowed and refill signals on the hot path', function (): void {
    $signals = $this->recordSignals();
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $client->commit($client->reserve('org_a', 'api.calls', 5), 5);

    expect($signals->allowed)->toHaveCount(1)
        ->and($signals->allowed[0]['meter'])->toBe('api.calls')
        ->and($signals->allowed[0]['backed_by_lease'])->toBeTrue()
        ->and($signals->refilled)->toHaveCount(1)
        ->and($signals->refilled[0]['granted'])->toBe(100);
});

it('emits a denied signal when the allowance is exhausted', function (): void {
    $signals = $this->recordSignals();
    $this->transport()->grant('org_a', 'api.calls', 0);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    expect(fn () => $client->reserve('org_a', 'api.calls', 5))->toThrow(QuotaExceeded::class);

    expect($signals->denied)->toHaveCount(1)
        ->and($signals->denied[0]['reason'])->toBe('quota_exhausted');
});

it('emits a reported signal when the reporter flushes', function (): void {
    $signals = $this->recordSignals();
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $client->commit($client->reserve('org_a', 'api.calls', 5), 5);
    $client->report();

    expect($signals->reported)->toBe([1]);
});

it('applies the entitlement weight to convert raw units into billable cost', function (): void {
    $entitlement = new Entitlement('api.calls', enabled: true, allowance: 100, weight: 2.5, overage: 'bill');

    expect($entitlement->cost(10))->toBe(25.0)
        ->and($entitlement->cost(0))->toBe(0.0);

    $entitlements = new Entitlements('org_a', ['api.calls' => $entitlement]);

    expect($entitlements->cost('api.calls', 4))->toBe(10.0)
        // Deny-by-default: an unknown meter has no weight and costs nothing.
        ->and($entitlements->cost('unknown.meter', 100))->toBe(0.0);
});

it('reads the weighted cost through the client', function (): void {
    $this->transport()->entitlement('org_a', new Entitlement('tokens', enabled: true, allowance: 0, weight: 0.001, overage: 'bill'));
    $client = $this->makeBillingClient();

    expect($client->cost('org_a', 'tokens', 2_000))->toBe(2.0);
});
