<?php

declare(strict_types=1);

it('reclaims an abandoned single-meter hold to the local slice after its TTL', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $this->billingNowMs = 1_000_000;
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, reservationTtl: 300);

    // Hold units but never commit or release — simulate a crashed request.
    $client->reserve('org_a', 'api.calls', 40);
    expect($client->balance('org_a', 'api.calls'))->toBe(60);

    // Before the TTL, the sweeper reclaims nothing.
    $this->billingNowMs = 1_000_000 + 299_000;
    expect($this->sweeper()->sweep($this->billingNowMs))->toBe(0)
        ->and($client->balance('org_a', 'api.calls'))->toBe(60);

    // Past the TTL, the abandoned hold's units return to the slice.
    $this->billingNowMs = 1_000_000 + 301_000;
    expect($this->sweeper()->sweep($this->billingNowMs))->toBe(1)
        ->and($client->balance('org_a', 'api.calls'))->toBe(100);
});

it('reclaims every meter of an abandoned multi-meter hold', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 500);
    $this->billingNowMs = 2_000_000;
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, reservationTtl: 60);

    $client->reserve('org_a', ['api.calls' => 30, 'storage.gb' => 10]);
    expect($client->balance('org_a', 'api.calls'))->toBe(70)
        ->and($client->balance('org_a', 'storage.gb'))->toBe(90);

    $this->billingNowMs = 2_000_000 + 61_000;
    expect($this->sweeper()->sweep($this->billingNowMs))->toBe(1)
        ->and($client->balance('org_a', 'api.calls'))->toBe(100)
        ->and($client->balance('org_a', 'storage.gb'))->toBe(100);
});

it('does not reclaim a committed hold — commit closes the reservation', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $this->billingNowMs = 3_000_000;
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, reservationTtl: 60);

    $reservation = $client->reserve('org_a', 'api.calls', 40);
    $client->commit($reservation, 40); // settles and closes the registry entry

    // Even long past the TTL there is nothing to reclaim, and committing must not have
    // wrongly credited the slice back.
    $this->billingNowMs = 3_000_000 + 120_000;
    expect($this->sweeper()->sweep($this->billingNowMs))->toBe(0)
        ->and($client->balance('org_a', 'api.calls'))->toBe(60);
});

it('does not double-reclaim across repeated sweeps', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $this->billingNowMs = 4_000_000;
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, reservationTtl: 60);

    $client->reserve('org_a', 'api.calls', 40);

    $this->billingNowMs = 4_000_000 + 61_000;
    expect($this->sweeper()->sweep($this->billingNowMs))->toBe(1)
        ->and($client->balance('org_a', 'api.calls'))->toBe(100);

    // A second sweep finds the reservation already closed — no double give-back.
    expect($this->sweeper()->sweep($this->billingNowMs))->toBe(0)
        ->and($client->balance('org_a', 'api.calls'))->toBe(100);
});
