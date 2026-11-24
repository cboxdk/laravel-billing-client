<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\Stores\CacheLeaseStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * A lease store where another worker on the node takes `$steal` units the moment a refill puts
 * them in the slice, as it can between a refill and the reservation's own take.
 */
function storeWithAConcurrentTaker(int $steal): LocalLeaseStore
{
    return new class(new CacheLeaseStore(new Repository(new ArrayStore)), $steal) implements LocalLeaseStore
    {
        public function __construct(private readonly LocalLeaseStore $inner, private int $steal) {}

        public function remaining(string $org, string $meter): int
        {
            return $this->inner->remaining($org, $meter);
        }

        public function addLease(string $org, string $meter, int $granted): void
        {
            $this->inner->addLease($org, $meter, $granted);
            if ($this->steal > 0 && $this->inner->tryTake($org, $meter, $this->steal)) {
                $this->steal = 0;
            }
        }

        public function tryTake(string $org, string $meter, int $amount): bool
        {
            return $this->inner->tryTake($org, $meter, $amount);
        }

        public function giveBack(string $org, string $meter, int $amount): void
        {
            $this->inner->giveBack($org, $meter, $amount);
        }
    };
}

it('holds a reservation whose refill Billing covered, even when another worker takes from the slice first (#427)', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, store: storeWithAConcurrentTaker(30));

    // The refill leases 100; the other worker takes 30 before this reservation takes its 80.
    $reservation = $client->reserve('org_a', 'api.calls', 80);

    expect($reservation->backedByLease)->toBeTrue()
        ->and($this->transport()->leaseCalls())->toBe(1);
});

it('still refuses a reservation when Billing granted less than it needs', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 50);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, store: storeWithAConcurrentTaker(30));

    expect(fn () => $client->reserve('org_a', 'api.calls', 80))->toThrow(QuotaExceeded::class);
    // The 50 granted stay in the slice for reservations they cover, less the other worker's 30.
    expect($client->balance('org_a', 'api.calls'))->toBe(20);
});
