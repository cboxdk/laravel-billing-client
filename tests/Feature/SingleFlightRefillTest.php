<?php

declare(strict_types=1);

use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Stores\CacheLeaseStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('makes exactly one lease round-trip to refill an empty slice', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $arrayStore = new ArrayStore;
    $store = new CacheLeaseStore(new Repository($arrayStore));
    $manager = new LeaseManager($this->transport(), $store, leaseSize: 100, refillThreshold: 0, locks: $arrayStore);

    $granted = $manager->refill('org_a', 'api.calls', 50);

    expect($granted)->toBe(100)
        ->and($this->transport()->leaseCalls())->toBe(1)
        ->and($store->remaining('org_a', 'api.calls'))->toBe(100);
});

it('coalesces a concurrent burst: a waiter reuses the holder-refilled slice with no second lease call', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $arrayStore = new ArrayStore;
    $store = new CacheLeaseStore(new Repository($arrayStore));
    $manager = new LeaseManager($this->transport(), $store, leaseSize: 100, refillThreshold: 0, locks: $arrayStore);

    // Simulate the single-flight HOLDER: it grabs the per-(org, meter) refill lock and,
    // exactly as a real refill would, fills the local slice before releasing.
    $lock = $arrayStore->lock('cbox-billing-client:refill:org_a:api.calls', 10);
    expect($lock->get())->toBeTrue();
    $store->addLease('org_a', 'api.calls', 100);
    $lock->release();

    // A WAITER now refills the same key. Under the lock it double-checks the slice,
    // finds the holder already covered its need, and reuses it — issuing NO lease call.
    $granted = $manager->refill('org_a', 'api.calls', 50);

    expect($granted)->toBe(0)                       // reused, not re-leased
        ->and($this->transport()->leaseCalls())->toBe(0) // no thundering-herd round-trip
        ->and($store->remaining('org_a', 'api.calls'))->toBe(100);
});

it('coalesces a burst of reserves that empties a slice into a single refill', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 10_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    // Nineteen reserves of 5 (95 units) fit inside a single 100-unit lease — the burst
    // triggers ONE refill round-trip, not one per reserve.
    for ($i = 0; $i < 19; $i++) {
        $client->commit($client->reserve('org_a', 'api.calls', 5), 5);
    }

    expect($this->transport()->leaseCalls())->toBe(1)
        ->and($client->balance('org_a', 'api.calls'))->toBe(5);
});
