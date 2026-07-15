<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Leasing;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Exceptions\TransportException;

/**
 * Acquires and refills leased slices of allowance from the remote billing service
 * into the node-local {@see LocalLeaseStore}. It is the bridge between the tier-2
 * remote authority (the transport) and the tier-1 hot path (the store): the
 * {@see BillingClient} asks it to top the local lease up when a
 * take runs short, and it leases at least {@see $leaseSize} units per hop so a large
 * single hold can still be satisfied in one round-trip.
 *
 * A refill can end three ways, which the client distinguishes:
 *  - granted > 0 — units were added locally.
 *  - granted = 0 — billing reached but the central allowance is exhausted (SEMANTIC).
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
    ) {}

    /**
     * Lease a fresh slice for (org, meter) sized to at least `$want` (and never below
     * the configured lease size) and add whatever billing granted to the local store.
     * Returns the number of units granted (0 when the central allowance is exhausted).
     *
     * @throws TransportException when billing cannot be reached
     */
    public function refill(string $org, string $meter, int $want = 1): int
    {
        $size = max($this->leaseSize, $want);

        $grant = $this->transport->lease($org, $meter, $size);

        $this->store->addLease($org, $meter, $grant->granted);

        return $grant->granted;
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
}
