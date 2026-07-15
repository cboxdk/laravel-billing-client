<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Leasing;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Contracts\BillingSignals;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Signals\NullBillingSignals;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Acquires and refills leased slices of allowance from the remote billing service
 * into the node-local {@see LocalLeaseStore}. It is the bridge between the tier-2
 * remote authority (the transport) and the tier-1 hot path (the store): the
 * {@see BillingClient} asks it to top the local lease up when a
 * take runs short, and it leases at least {@see $leaseSize} units per hop so a large
 * single hold can still be satisfied in one round-trip.
 *
 * Refills are SINGLE-FLIGHT (when a {@see LockProvider} is supplied): a burst that
 * empties a lease is coalesced behind a per-(org, meter) lock so it triggers ONE
 * refill round-trip, not a thundering herd. A concurrent caller waits for the holder,
 * then re-checks the local slice and reuses the freshly-leased units instead of
 * issuing its own redundant lease. When no lock provider is available the refill runs
 * directly — correct, just not coalesced.
 *
 * A refill can end three ways, which the client distinguishes:
 *  - granted > 0 — units were added locally.
 *  - granted = 0 — billing reached but the central allowance is exhausted (SEMANTIC),
 *    or a waiter reused a slice the holder already refilled (no round-trip made).
 *  - {@see TransportException} — billing unreachable (INFRASTRUCTURE); propagated so
 *    the client can apply its failure policy.
 */
class LeaseManager
{
    public function __construct(
        private readonly BillingTransport $transport,
        private readonly LocalLeaseStore $store,
        private readonly int $leaseSize = 100,
        private readonly int $refillThreshold = 20,
        private readonly ?LockProvider $locks = null,
        private readonly int $lockTtl = 10,
        private readonly int $lockWait = 5,
        private readonly BillingSignals $signals = new NullBillingSignals,
        private readonly string $lockPrefix = 'cbox-billing-client:refill:',
    ) {}

    /**
     * Lease a fresh slice for (org, meter) sized to at least `$want` (and never below
     * the configured lease size) and add whatever billing granted to the local store.
     * Returns the number of units granted (0 when the central allowance is exhausted,
     * or when a single-flight waiter reused an already-refilled slice).
     *
     * @throws TransportException when billing cannot be reached
     */
    public function refill(string $org, string $meter, int $want = 1): int
    {
        if ($this->locks === null) {
            return $this->fetch($org, $meter, $want);
        }

        $lock = $this->locks->lock($this->lockPrefix.$org.':'.$meter, $this->lockTtl);

        try {
            /** @var int $granted */
            $granted = $lock->block($this->lockWait, function () use ($org, $meter, $want): int {
                // Double-check under the lock: a holder may already have refilled the
                // slice while we waited — reuse it rather than issue a second lease.
                if ($this->store->remaining($org, $meter) >= $want) {
                    return 0;
                }

                return $this->fetch($org, $meter, $want);
            });

            return $granted;
        } catch (LockTimeoutException) {
            // Pathological contention: could not join the single flight in time. Fall
            // back to a direct refill so the caller is never wrongly starved — liveness
            // over coalescing.
            return $this->fetch($org, $meter, $want);
        }
    }

    /**
     * Opportunistically top the local lease up when it is at or below the refill
     * threshold, so the hot path rarely blocks on the network. Best-effort: a
     * transport fault is swallowed here because the next reserve on a depleted lease
     * will refill (or apply the failure policy) authoritatively.
     */
    public function refillIfLow(string $org, string $meter): void
    {
        if ($this->store->remaining($org, $meter) > $this->refillThreshold) {
            return;
        }

        try {
            $this->refill($org, $meter);
        } catch (TransportException) {
            // Deferred to the next authoritative reserve; not fatal to this request.
        }
    }

    public function refillThreshold(): int
    {
        return $this->refillThreshold;
    }

    /**
     * The actual lease round-trip: ask billing for a slice and add the grant locally.
     *
     * @throws TransportException when billing cannot be reached
     */
    private function fetch(string $org, string $meter, int $want): int
    {
        $size = max($this->leaseSize, $want);

        $grant = $this->transport->lease($org, $meter, $size);

        $this->store->addLease($org, $meter, $grant->granted);

        if ($grant->granted > 0) {
            $this->signals->refilled($org, $meter, $grant->granted);
        }

        return $grant->granted;
    }
}
