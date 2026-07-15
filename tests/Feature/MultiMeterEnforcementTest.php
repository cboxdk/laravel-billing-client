<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\ValueObjects\ReservationSet;

it('reserves a set of meters atomically from each meter local lease', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 500);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $set = $client->reserve('org_a', ['api.calls' => 10, 'storage.gb' => 4]);

    expect($set)->toBeInstanceOf(ReservationSet::class)
        ->and($set->meters())->toBe(['api.calls', 'storage.gb'])
        ->and($client->balance('org_a', 'api.calls'))->toBe(90)  // 100 leased - 10 held
        ->and($client->balance('org_a', 'storage.gb'))->toBe(96); // 100 leased - 4 held
});

it('settles per-meter actuals, returning each leftover to its own slice', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 500);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $set = $client->reserve('org_a', ['api.calls' => 10, 'storage.gb' => 8]);
    $client->commit($set, ['api.calls' => 7, 'storage.gb' => 2]);

    // Each meter's own leftover (3 and 6) returns to its own slice; usage is buffered
    // per meter, never collapsed into one number.
    expect($client->balance('org_a', 'api.calls'))->toBe(93)
        ->and($client->balance('org_a', 'storage.gb'))->toBe(98)
        ->and($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(7)
        ->and($this->usageBuffer()->cumulative('org_a', 'storage.gb'))->toBe(2);
});

it('rolls back every already-held bucket when one meter cannot be satisfied', function (): void {
    // api.calls has plenty; storage.gb is exhausted centrally — the set must fail as one
    // and the api.calls units already taken must be returned.
    $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 0);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    expect(fn () => $client->reserve('org_a', ['api.calls' => 10, 'storage.gb' => 4]))
        ->toThrow(QuotaExceeded::class);

    // All-or-nothing: the api.calls slice is whole again (nothing stranded), and neither
    // meter buffered any usage.
    $leasedApi = $this->transport()->leasedOut('org_a', 'api.calls');
    expect($client->balance('org_a', 'api.calls'))->toBe($leasedApi)
        ->and($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(0)
        ->and($this->usageBuffer()->cumulative('org_a', 'storage.gb'))->toBe(0);
});

it('releases every bucket in a set back to its slice', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 500);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $set = $client->reserve('org_a', ['api.calls' => 10, 'storage.gb' => 8]);
    $client->release($set);

    expect($client->balance('org_a', 'api.calls'))->toBe(100)
        ->and($client->balance('org_a', 'storage.gb'))->toBe(100);
});

it('rejects a commit that is missing a held meter', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 500);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $set = $client->reserve('org_a', ['api.calls' => 10, 'storage.gb' => 8]);

    expect(fn () => $client->commit($set, ['api.calls' => 5]))
        ->toThrow(InvalidArgumentException::class);

    // Validation aborts before any mutation — nothing buffered, slices unchanged.
    expect($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(0)
        ->and($client->balance('org_a', 'api.calls'))->toBe(90);
});

it('rejects an over-estimate actual for one meter without half-applying the set', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 500);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $set = $client->reserve('org_a', ['api.calls' => 10, 'storage.gb' => 8]);

    expect(fn () => $client->commit($set, ['api.calls' => 5, 'storage.gb' => 99]))
        ->toThrow(InvalidArgumentException::class);

    expect($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(0)
        ->and($this->usageBuffer()->cumulative('org_a', 'storage.gb'))->toBe(0);
});

it('treats a single-meter reserve as the degenerate one-bucket case', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    // The single-meter signature keeps working exactly as before.
    $reservation = $client->reserve('org_a', 'api.calls', 5);
    $client->commit($reservation, 5);

    expect($reservation->meter)->toBe('api.calls')
        ->and($client->balance('org_a', 'api.calls'))->toBe(95)
        ->and($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(5);
});

it('rejects an empty multi-meter reservation', function (): void {
    $client = $this->makeBillingClient();

    expect(fn () => $client->reserve('org_a', []))
        ->toThrow(InvalidArgumentException::class);
});
