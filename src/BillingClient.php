<?php

declare(strict_types=1);

namespace Cbox\Billing\Client;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\MeterActual;
use Cbox\Billing\Client\ValueObjects\MeterEstimate;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;
use Cbox\Billing\Client\ValueObjects\Reservation;
use Closure;
use InvalidArgumentException;

/**
 * The app-facing, two-tier enforcement SDK a PRODUCT app embeds to bill against a
 * remote Cbox Billing service. It is the REMOTE-client analogue of the engine's
 * lease-backed enforcement:
 *
 *  - Tier 1 (hot path, no network): a reservation takes units from a node-local
 *    leased slice via an atomic decrement-and-compensate on the {@see LocalLeaseStore}
 *    — no per-request round-trip to billing.
 *  - Tier 2 (background): when the local slice runs short the {@see LeaseManager}
 *    leases a fresh slice from billing, and committed usage is appended to a durable
 *    {@see UsageBuffer} and reported CUMULATIVELY by the {@see UsageReporter}.
 *
 * Because leasing is pessimistic (billing reserves the granted units centrally), an
 * org can never exceed its allowance beyond a bounded overshoot of roughly
 * `lease_size × nodes` — the leased-but-unused units stranded across nodes. The only
 * other drift is reporting lag, which the cumulative, self-correcting report closes.
 *
 * Failure handling splits by CAUSE (mirroring the engine's ADR-0004):
 *  - An EXHAUSTED allowance (billing granted zero) is a SEMANTIC hard limit and always
 *    fails closed — {@see QuotaExceeded}, regardless of the failure policy.
 *  - An UNREACHABLE billing service is an INFRASTRUCTURE fault, resolved by the
 *    deployment's {@see FailurePolicy}: fail-open admits the request best-effort (usage
 *    is still buffered and reconciled later); fail-closed surfaces the transport error.
 */
class BillingClient
{
    /** @var Closure(): string */
    private Closure $ids;

    /**
     * @param  (Closure(): string)|null  $ids  reservation-id factory (deterministic in tests)
     */
    public function __construct(
        private readonly LocalLeaseStore $store,
        private readonly UsageBuffer $buffer,
        private readonly LeaseManager $leases,
        private readonly UsageReporter $reporter,
        private readonly BillingTransport $transport,
        private readonly FailurePolicy $failurePolicy = FailurePolicy::Allow,
        ?Closure $ids = null,
    ) {
        $this->ids = $ids ?? static fn (): string => bin2hex(random_bytes(16));
    }

    /**
     * Hold `estimate` units for `meter` on `org` against the local lease, refilling
     * from billing if the slice is short. Runs entirely local when the slice covers
     * the hold; touches the network only to refill.
     *
     * @throws QuotaExceeded when the central allowance is exhausted (the hard limit)
     * @throws TransportException when billing is unreachable AND the failure policy is
     *                            fail-closed
     * @throws InvalidArgumentException when `estimate` is not positive
     */
    public function reserve(string $org, string $meter, int $estimate): Reservation
    {
        if ($estimate <= 0) {
            throw new InvalidArgumentException('Reservation estimate must be a positive number of units.');
        }

        try {
            $taken = $this->holdLease($org, $meter, $estimate);
        } catch (TransportException $e) {
            // Infrastructure fault refilling the lease — resolve by policy, not silently.
            if ($this->failurePolicy->admitsOnOutage()) {
                // Fail open: admit best-effort. No local units were taken, so the
                // reservation is not lease-backed; usage is still buffered on commit and
                // reconciled from the cumulative ledger once billing is reachable again.
                return new Reservation(($this->ids)(), $org, $meter, $estimate, backedByLease: false);
            }

            throw $e;
        }

        if (! $taken) {
            // Billing was reached but the central allowance is exhausted — semantic
            // hard limit, always fail closed.
            throw new QuotaExceeded($org, $meter, $estimate);
        }

        // Opportunistically top the slice up so the next hold rarely blocks on billing.
        $this->leases->refillIfLow($org, $meter);

        return new Reservation(($this->ids)(), $org, $meter, $estimate);
    }

    /**
     * Settle a reservation to the actual amount used (must be `<=` the reserved
     * estimate). Unused lease-backed units return to the local slice, and any usage is
     * appended to the durable buffer for cumulative reporting.
     *
     * @throws InvalidArgumentException when `actual` is out of range
     */
    public function commit(Reservation $reservation, int $actual): void
    {
        if ($actual < 0 || $actual > $reservation->amount) {
            throw new InvalidArgumentException('Committed amount must be between 0 and the reserved estimate.');
        }

        $leftover = $reservation->amount - $actual;

        if ($reservation->backedByLease && $leftover > 0) {
            $this->store->giveBack($reservation->org, $reservation->meter, $leftover);
        }

        if ($actual > 0) {
            $this->buffer->record($reservation->org, $reservation->meter, $actual);
        }
    }

    /** Release a held reservation without charging (error paths). */
    public function release(Reservation $reservation): void
    {
        if ($reservation->backedByLease) {
            $this->store->giveBack($reservation->org, $reservation->meter, $reservation->amount);
        }
    }

    /**
     * Non-throwing pre-check: could `org` reserve `n` units of `meter` right now? It
     * consults the local slice and refills once if short, without consuming units. On
     * an exhausted allowance it returns false; on an unreachable billing service it
     * returns the failure policy's admit decision.
     */
    public function can(string $org, string $meter, int $n): bool
    {
        if ($n <= 0) {
            return false;
        }

        if ($this->store->remaining($org, $meter) >= $n) {
            return true;
        }

        try {
            $this->leases->refill($org, $meter, $n);
        } catch (TransportException) {
            return $this->failurePolicy->admitsOnOutage();
        }

        return $this->store->remaining($org, $meter) >= $n;
    }

    /** Locally-known remaining leased balance for (org, meter) — for UX pre-checks only. */
    public function balance(string $org, string $meter): int
    {
        return $this->store->remaining($org, $meter);
    }

    /**
     * Flush the durable usage ledger to billing now (the reporter is normally run on a
     * schedule). Returns the number of orgs successfully reported.
     */
    public function report(?string $org = null): int
    {
        return $this->reporter->flush($org);
    }

    /**
     * The authoritative synchronous reservation path (`POST /api/v1/reserve`) — a
     * round-trip to billing for callers that need a central decision rather than the
     * leased hot path. Pair with {@see commitRemote()}.
     *
     * @param  list<MeterEstimate>  $meters
     *
     * @throws TransportException on an infrastructure fault
     */
    public function reserveRemote(string $org, array $meters): RemoteReservation
    {
        return $this->transport->reserve($org, $meters);
    }

    /**
     * Settle an authoritative remote reservation (`POST /api/v1/commit`).
     *
     * @param  list<MeterActual>  $actuals
     *
     * @throws TransportException on an infrastructure fault
     */
    public function commitRemote(string $reservationId, array $actuals): void
    {
        $this->transport->commit($reservationId, $actuals);
    }

    /**
     * Read `org`'s entitlement set from billing (`GET /api/v1/entitlements/{org}`).
     *
     * @throws TransportException on an infrastructure fault
     */
    public function entitlements(string $org): Entitlements
    {
        return $this->transport->entitlements($org);
    }

    /**
     * Take `$amount` from the local lease, refilling once from billing if the slice is
     * short. Returns true when taken, false when the central allowance is exhausted;
     * a {@see TransportException} propagates when billing is unreachable during the
     * needed refill.
     *
     * @throws TransportException when billing cannot be reached to refill
     */
    private function holdLease(string $org, string $meter, int $amount): bool
    {
        if ($this->store->tryTake($org, $meter, $amount)) {
            return true;
        }

        $granted = $this->leases->refill($org, $meter, $amount);

        if ($granted <= 0) {
            return false;
        }

        return $this->store->tryTake($org, $meter, $amount);
    }
}
