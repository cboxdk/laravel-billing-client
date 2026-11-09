<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Leasing;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Contracts\AllowanceSnapshots;
use Cbox\Billing\Client\Contracts\BillingSignals;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Jobs\RefillLease;
use Cbox\Billing\Client\Signals\NullBillingSignals;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

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
 *
 * Given {@see AllowanceSnapshots}, every answered lease is recorded there, and the
 * organization's entitlements are re-read at most once per refresh interval, so the
 * client can enforce the last known allowance if billing later becomes unreachable.
 *
 * NO REQUEST WAITS ON A TOP-UP (since 0.5.0). Given a `$queueRefill` callback, a lease that
 * runs low is topped up by a queued {@see RefillLease} job and the request keeps serving from
 * the local slice. Only a slice too empty for the hold refills synchronously, and given a
 * {@see RefillBreaker}, not even that while Billing was just found unreachable: the breaker
 * fails the refill at once as {@see BillingUnavailable}, so the failure policy (and its grace)
 * decides without a timeout.
 */
class LeaseManager
{
    /**
     * @param  (Closure(string, string): void)|null  $queueRefill  queues a background top-up for (org, meter); null refills inline
     * @param  RefillBreaker|null  $breaker  stops synchronous refills for a while after Billing was found unreachable
     */
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
        private readonly ?AllowanceSnapshots $snapshots = null,
        private readonly ?Closure $queueRefill = null,
        private readonly ?RefillBreaker $breaker = null,
    ) {}

    /**
     * Lease a fresh slice for (org, meter) sized to at least `$want` (and never below
     * the configured lease size) and add whatever billing granted to the local store.
     * Returns the number of units granted (0 when the central allowance is exhausted,
     * or when a single-flight waiter reused an already-refilled slice).
     *
     * @throws TransportException when billing cannot be reached, or the breaker is open
     */
    public function refill(string $org, string $meter, int $want = 1): int
    {
        return $this->refillGuarded($org, $meter, $want, probe: false);
    }

    /**
     * What the queued {@see RefillLease} runs: top the slice up if it is still at or below
     * the threshold. It ignores an open breaker, because it is the background probe that
     * finds Billing back; its success closes the breaker.
     *
     * @throws TransportException when billing cannot be reached
     */
    public function refillInBackground(string $org, string $meter): int
    {
        if ($this->store->remaining($org, $meter) > $this->refillThreshold) {
            return 0;
        }

        // Want more than the threshold, so the single-flight double-check skips only when
        // another refill already lifted the slice above it.
        return $this->refillGuarded($org, $meter, $this->refillThreshold + 1, probe: true);
    }

    /** True while a recent synchronous refill found Billing unreachable. */
    public function breakerOpen(): bool
    {
        return $this->breaker?->isOpen() === true;
    }

    /**
     * @throws TransportException when billing cannot be reached, or the breaker is open
     */
    private function refillGuarded(string $org, string $meter, int $want, bool $probe): int
    {
        $this->guard($probe);

        if ($this->locks === null) {
            return $this->fetch($org, $meter, $want);
        }

        $lock = $this->locks->lock($this->lockPrefix.$org.':'.$meter, $this->lockTtl);

        try {
            /** @var int $granted */
            $granted = $lock->block($this->lockWait, function () use ($org, $meter, $want, $probe): int {
                // Double-check under the lock: a holder may already have refilled the
                // slice while we waited — reuse it rather than issue a second lease.
                if ($this->store->remaining($org, $meter) >= $want) {
                    return 0;
                }

                // The holder we waited for may just have found Billing away: do not wait again.
                $this->guard($probe);

                return $this->fetch($org, $meter, $want);
            });

            return $granted;
        } catch (LockTimeoutException) {
            // Pathological contention: could not join the single flight in time. Fall
            // back to a direct refill so the caller is never wrongly starved — liveness
            // over coalescing. Unless the holder just found Billing away.
            $this->guard($probe);

            return $this->fetch($org, $meter, $want);
        }
    }

    /**
     * Top the local lease up when it is at or below the refill threshold. With a
     * `$queueRefill` callback (how the service provider wires it) the top-up is QUEUED and
     * this returns at once: the request never waits on Billing for a slice it does not need
     * yet. Without one it refills inline, as before 0.5.0. Best-effort either way: a fault is
     * swallowed, because the next reserve on a depleted lease refills (or applies the failure
     * policy) authoritatively.
     */
    public function refillIfLow(string $org, string $meter): void
    {
        if ($this->store->remaining($org, $meter) > $this->refillThreshold) {
            return;
        }

        if ($this->queueRefill !== null) {
            try {
                ($this->queueRefill)($org, $meter);
            } catch (Throwable) {
                // A queue that is down must not fail the request; the next low reserve retries.
            }

            return;
        }

        try {
            $this->refill($org, $meter, $this->refillThreshold + 1);
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

        try {
            $grant = $this->transport->lease($org, $meter, $size);
        } catch (BillingUnavailable $e) {
            $this->breaker?->trip();

            throw $e;
        }

        $this->breaker?->reset();

        $this->store->addLease($org, $meter, $grant->granted);

        if ($grant->granted > 0) {
            $this->signals->refilled($org, $meter, $grant->granted);
        }

        $this->remember($org, $meter, $size, $grant->granted);

        return $grant->granted;
    }

    /**
     * @throws BillingUnavailable while the breaker is open (a probe passes)
     */
    private function guard(bool $probe): void
    {
        if (! $probe && $this->breaker?->isOpen() === true) {
            throw new BillingUnavailable('Billing was unreachable moments ago; not waiting on a lease refill while the breaker is open.', '/api/v1/leases');
        }
    }

    /**
     * Keep what this answer told us for an outage. Best-effort: billing just answered, so
     * an entitlement read that fails now is retried after the refresh interval.
     */
    private function remember(string $org, string $meter, int $requested, int $granted): void
    {
        if ($this->snapshots === null) {
            return;
        }

        if ($this->snapshots->claimRefresh($org)) {
            try {
                $this->snapshots->remember($this->transport->entitlements($org));
            } catch (TransportException) {
                // Keep the previous snapshot; the next interval reads again.
            }
        }

        $this->snapshots->recordLease($org, $meter, $requested, $granted);
    }
}
