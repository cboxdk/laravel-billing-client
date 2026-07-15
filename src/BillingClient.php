<?php

declare(strict_types=1);

namespace Cbox\Billing\Client;

use Cbox\Billing\Client\Contracts\BillingSignals;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Contracts\ReservationRegistry;
use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\Signals\NullBillingSignals;
use Cbox\Billing\Client\ValueObjects\BucketHold;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\MeterActual;
use Cbox\Billing\Client\ValueObjects\MeterEstimate;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;
use Cbox\Billing\Client\ValueObjects\Reservation;
use Cbox\Billing\Client\ValueObjects\ReservationSet;
use Closure;
use InvalidArgumentException;
use Throwable;

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
 * Beyond the single-meter path it enforces MULTI-DIMENSIONAL requests: passing a
 * `[meter => estimate]` map reserves a SET of buckets, each taken from its own meter's
 * local lease all-or-nothing — if any bucket cannot be satisfied every already-held
 * bucket is rolled back and the reservation fails as one. The single-meter methods are
 * the degenerate one-bucket case.
 *
 * Because leasing is pessimistic (billing reserves the granted units centrally), an
 * org can never exceed its allowance beyond a bounded overshoot of roughly
 * `lease_size × nodes` — the leased-but-unused units stranded across nodes. A held
 * reservation is recorded with a TTL in the {@see ReservationRegistry} so a crashed
 * request's units are reclaimed to the local slice rather than leaked. The only other
 * drift is reporting lag, which the cumulative, self-correcting report closes.
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

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param  (Closure(): string)|null  $ids  reservation-id factory (deterministic in tests)
     * @param  ReservationRegistry|null  $registry  durable register of held reservations for TTL recovery; omit to disable
     * @param  int  $reservationTtl  seconds a held reservation lives before the sweeper reclaims it
     * @param  (Closure(): int)|null  $clock  millisecond-epoch clock (deterministic in tests)
     */
    public function __construct(
        private readonly LocalLeaseStore $store,
        private readonly UsageBuffer $buffer,
        private readonly LeaseManager $leases,
        private readonly UsageReporter $reporter,
        private readonly BillingTransport $transport,
        private readonly FailurePolicy $failurePolicy = FailurePolicy::Allow,
        ?Closure $ids = null,
        private readonly ?ReservationRegistry $registry = null,
        private readonly BillingSignals $signals = new NullBillingSignals,
        private readonly int $reservationTtl = 300,
        ?Closure $clock = null,
    ) {
        $this->ids = $ids ?? static fn (): string => bin2hex(random_bytes(16));
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * Hold units against the local lease, refilling from billing if the slice is short.
     * Runs entirely local when the slice covers the hold; touches the network only to
     * refill.
     *
     * Pass a `meter` name and `estimate` for a single meter, or a `[meter => estimate]`
     * map (with no `estimate`) to reserve a multi-meter set all-or-nothing.
     *
     * @param  string|array<string, int>  $meter  a meter name, or a [meter => estimate] map for a set
     * @return ($meter is array ? ReservationSet : Reservation)
     *
     * @throws QuotaExceeded when a meter's central allowance is exhausted (the hard limit)
     * @throws TransportException when billing is unreachable AND the failure policy is fail-closed
     * @throws InvalidArgumentException when an estimate is not positive or the set is empty
     */
    public function reserve(string $org, string|array $meter, ?int $estimate = null): Reservation|ReservationSet
    {
        if (is_array($meter)) {
            return $this->reserveSet($org, $meter);
        }

        if ($estimate === null) {
            throw new InvalidArgumentException('A single-meter reservation requires an estimate.');
        }

        return $this->reserveOne($org, $meter, $estimate);
    }

    /**
     * Settle a reservation to the actual amount(s) used. For a single-meter
     * {@see Reservation} pass an integer `<=` the reserved estimate; for a
     * {@see ReservationSet} pass a `[meter => actual]` map covering every held meter.
     * Unused lease-backed units return to their slice, and usage is appended to the
     * durable buffer for cumulative reporting.
     *
     * @param  int|array<string, int>  $actual
     *
     * @throws InvalidArgumentException when an actual is out of range or a meter is missing
     */
    public function commit(Reservation|ReservationSet $reservation, int|array $actual): void
    {
        if ($reservation instanceof ReservationSet) {
            if (! is_array($actual)) {
                throw new InvalidArgumentException('A multi-meter reservation must be committed with a [meter => actual] map.');
            }

            $this->commitSet($reservation, $actual);

            return;
        }

        if (! is_int($actual)) {
            throw new InvalidArgumentException('A single-meter reservation must be committed with an integer actual.');
        }

        $this->commitOne($reservation, $actual);
    }

    /** Release a held reservation without charging (error paths). */
    public function release(Reservation|ReservationSet $reservation): void
    {
        if ($reservation instanceof ReservationSet) {
            foreach ($reservation->buckets as $bucket) {
                if ($bucket->backedByLease && $bucket->amount > 0) {
                    $this->store->giveBack($reservation->org, $bucket->meter, $bucket->amount);
                }
            }

            $this->closeReservation($reservation->id);

            return;
        }

        if ($reservation->backedByLease) {
            $this->store->giveBack($reservation->org, $reservation->meter, $reservation->amount);
        }

        $this->closeReservation($reservation->id);
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
     * Convert raw `$units` of `$meter` into weighted billable cost for `$org`, applying
     * the entitlement weight (`raw × weight`). Convenience over the pure
     * {@see Entitlements::cost()} — it reads the org's entitlements over the network, so
     * prefer caching the {@see Entitlements} and calling `cost()` on it in a hot loop.
     * An unknown/unentitled meter has no weight and costs nothing (deny-by-default).
     *
     * @throws TransportException on an infrastructure fault reading entitlements
     */
    public function cost(string $org, string $meter, int $units): float
    {
        return $this->entitlements($org)->cost($meter, $units);
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

    private function reserveOne(string $org, string $meter, int $estimate): Reservation
    {
        $hold = $this->holdBucket($org, $meter, $estimate);
        $id = ($this->ids)();

        $reservation = new Reservation($id, $org, $meter, $hold->amount, $hold->backedByLease);

        if ($hold->backedByLease) {
            $this->registerHolds($id, $org, [$meter => $hold->amount]);
            // Opportunistically top the slice up so the next hold rarely blocks on billing.
            $this->leases->refillIfLow($org, $meter);
        }

        $this->signals->allowed($org, $meter, $hold->amount, $hold->backedByLease);

        return $reservation;
    }

    /**
     * @param  array<string, int>  $buckets
     */
    private function reserveSet(string $org, array $buckets): ReservationSet
    {
        if ($buckets === []) {
            throw new InvalidArgumentException('A multi-meter reservation must carry at least one meter.');
        }

        $id = ($this->ids)();

        /** @var list<BucketHold> $held */
        $held = [];

        try {
            foreach ($buckets as $meter => $estimate) {
                $held[] = $this->holdBucket($org, $meter, $estimate);
            }
        } catch (Throwable $e) {
            // All-or-nothing: unwind every bucket already held, then fail as one.
            $this->rollBack($org, $held);

            throw $e;
        }

        $set = new ReservationSet($id, $org, $held);

        $this->registerHolds($id, $org, $set->backedHolds());

        foreach ($held as $bucket) {
            if ($bucket->backedByLease) {
                $this->leases->refillIfLow($org, $bucket->meter);
            }

            $this->signals->allowed($org, $bucket->meter, $bucket->amount, $bucket->backedByLease);
        }

        return $set;
    }

    /**
     * Hold one bucket against its meter's local lease. Returns the hold on success;
     * throws {@see QuotaExceeded} (exhausted allowance) or {@see TransportException}
     * (unreachable, fail-closed) so a multi-meter set can roll the whole thing back. A
     * fail-open outage returns an unbacked hold rather than throwing.
     */
    private function holdBucket(string $org, string $meter, int $estimate): BucketHold
    {
        if ($estimate <= 0) {
            throw new InvalidArgumentException('Reservation estimate must be a positive number of units.');
        }

        try {
            $taken = $this->holdLease($org, $meter, $estimate);
        } catch (TransportException $e) {
            // Infrastructure fault refilling the lease — resolve by policy, not silently.
            if ($this->failurePolicy->admitsOnOutage()) {
                // Fail open: admit best-effort. No local units were taken, so the hold
                // is not lease-backed; usage is still buffered on commit and reconciled
                // from the cumulative ledger once billing is reachable again.
                return new BucketHold($meter, $estimate, backedByLease: false);
            }

            throw $e;
        }

        if (! $taken) {
            // Billing was reached but the central allowance is exhausted — semantic
            // hard limit, always fail closed.
            $this->signals->denied($org, $meter, $estimate, 'quota_exhausted');

            throw new QuotaExceeded($org, $meter, $estimate);
        }

        return new BucketHold($meter, $estimate, backedByLease: true);
    }

    /**
     * Return every fully-held bucket's lease-backed units when a later bucket in a set
     * is refused — the multi-meter reservation is all-or-nothing.
     *
     * @param  list<BucketHold>  $held
     */
    private function rollBack(string $org, array $held): void
    {
        foreach ($held as $bucket) {
            if ($bucket->backedByLease && $bucket->amount > 0) {
                $this->store->giveBack($org, $bucket->meter, $bucket->amount);
            }
        }
    }

    private function commitOne(Reservation $reservation, int $actual): void
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

        $this->closeReservation($reservation->id);
    }

    /**
     * @param  array<string, int>  $actuals
     */
    private function commitSet(ReservationSet $set, array $actuals): void
    {
        // Validate every bucket BEFORE mutating any, so a bad actual aborts the whole
        // settle rather than half-applying it.
        foreach ($set->buckets as $bucket) {
            if (! array_key_exists($bucket->meter, $actuals)) {
                throw new InvalidArgumentException("Missing committed usage for meter [{$bucket->meter}].");
            }

            $actual = $actuals[$bucket->meter];

            if ($actual < 0 || $actual > $bucket->amount) {
                throw new InvalidArgumentException("Committed amount for meter [{$bucket->meter}] must be between 0 and the reserved estimate.");
            }
        }

        foreach ($set->buckets as $bucket) {
            $actual = $actuals[$bucket->meter];
            $leftover = $bucket->amount - $actual;

            if ($bucket->backedByLease && $leftover > 0) {
                $this->store->giveBack($set->org, $bucket->meter, $leftover);
            }

            if ($actual > 0) {
                $this->buffer->record($set->org, $bucket->meter, $actual);
            }
        }

        $this->closeReservation($set->id);
    }

    /**
     * @param  array<string, int>  $holds
     */
    private function registerHolds(string $id, string $org, array $holds): void
    {
        if ($this->registry === null || $holds === []) {
            return;
        }

        $this->registry->open($id, $org, $holds, ($this->clock)() + $this->reservationTtl * 1000);
    }

    private function closeReservation(string $id): void
    {
        $this->registry?->close($id);
    }

    /**
     * Take `$amount` from the local lease, refilling once from billing if the slice is
     * short. Returns true when taken, false when the central allowance is exhausted; a
     * {@see TransportException} propagates when billing is unreachable during the needed
     * refill.
     *
     * @throws TransportException when billing cannot be reached to refill
     */
    private function holdLease(string $org, string $meter, int $amount): bool
    {
        if ($this->store->tryTake($org, $meter, $amount)) {
            return true;
        }

        // Slice short — refill (single-flight) then re-take. A refill that fetched
        // nothing (exhausted) leaves the slice empty, so the re-take fails and the
        // caller treats it as the hard limit; a refill that granted units (or that a
        // concurrent holder already applied) lets the re-take succeed.
        $this->leases->refill($org, $meter, $amount);

        return $this->store->tryTake($org, $meter, $amount);
    }
}
