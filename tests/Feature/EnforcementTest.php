<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\QuotaExceeded;

it('runs the hot path locally and only touches billing to refill the lease', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    // First reserve leases a slice (one network hop), then takes locally.
    $first = $client->reserve('org_a', 'api.calls', 5);
    $client->commit($first, 5);

    expect($this->transport()->leaseCalls())->toBe(1)
        ->and($client->balance('org_a', 'api.calls'))->toBe(95);

    // Subsequent reserves inside the slice take locally — no new lease call.
    for ($i = 0; $i < 10; $i++) {
        $reservation = $client->reserve('org_a', 'api.calls', 5);
        $client->commit($reservation, 5);
    }

    expect($this->transport()->leaseCalls())->toBe(1)
        ->and($client->balance('org_a', 'api.calls'))->toBe(45);
});

it('refills the lease when the local slice runs short', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $client->commit($client->reserve('org_a', 'api.calls', 80), 80); // slice: 100 -> 20
    $client->commit($client->reserve('org_a', 'api.calls', 80), 80); // short -> refill -> 120 -> 40

    expect($this->transport()->leaseCalls())->toBe(2)
        ->and($this->transport()->leasedOut('org_a', 'api.calls'))->toBe(200);
});

it('hard-blocks at the leased ceiling when the central allowance is exhausted', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 250);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $taken = 0;
    try {
        for ($i = 0; $i < 100; $i++) {
            $reservation = $client->reserve('org_a', 'api.calls', 25);
            $client->commit($reservation, 25);
            $taken += 25;
        }
        $this->fail('Expected the hard limit to block once the allowance was exhausted.');
    } catch (QuotaExceeded $e) {
        // Reached the hard limit.
    }

    // Never granted more than the central allowance — the pessimistic-lease invariant.
    expect($taken)->toBeLessThanOrEqual(250)
        ->and($this->transport()->leasedOut('org_a', 'api.calls'))->toBeLessThanOrEqual(250)
        ->and($client->can('org_a', 'api.calls', 1))->toBeFalse();
});

it('bounds total leased units by the central allowance across many nodes', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 300);

    // Three independent "nodes", each its own local store but the same billing service.
    for ($node = 0; $node < 3; $node++) {
        $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

        try {
            for ($i = 0; $i < 100; $i++) {
                $client->commit($client->reserve('org_a', 'api.calls', 50), 50);
            }
        } catch (QuotaExceeded) {
            // node hit the shared ceiling
        }
    }

    // The sum leased across all nodes can never exceed the org's central allowance.
    expect($this->transport()->leasedOut('org_a', 'api.calls'))->toBeLessThanOrEqual(300);
});

it('returns the unused estimate to the local slice on commit', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $reservation = $client->reserve('org_a', 'api.calls', 10); // slice 100 -> 90
    $client->commit($reservation, 4);                           // leftover 6 returned -> 96

    expect($client->balance('org_a', 'api.calls'))->toBe(96)
        ->and($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(4);
});

it('returns the whole hold to the slice on release', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $reservation = $client->reserve('org_a', 'api.calls', 10); // 100 -> 90
    $client->release($reservation);                            // -> 100

    expect($client->balance('org_a', 'api.calls'))->toBe(100)
        ->and($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(0);
});

it('rejects a non-positive reserve estimate as a caller bug', function (): void {
    $client = $this->makeBillingClient();

    expect(fn () => $client->reserve('org_a', 'api.calls', 0))
        ->toThrow(InvalidArgumentException::class);
});
