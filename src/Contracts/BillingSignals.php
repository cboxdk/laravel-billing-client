<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Signals\LoggingBillingSignals;
use Cbox\Billing\Client\Signals\NullBillingSignals;

/**
 * The operator-facing observability channel for the enforcement hot path — so a host
 * can "meter the meter". Every enforcement decision and background action emits here:
 * an admitted reservation ({@see allowed()}), a hard-limit refusal ({@see denied()}),
 * a lease refill round-trip ({@see refilled()}), and a usage flush ({@see reported()}).
 *
 * The default binding is the no-op {@see NullBillingSignals}; a host may bind
 * {@see LoggingBillingSignals} or its own metrics/alerting implementation. Handlers
 * must not throw — a broken observer must never break enforcement — so implementations
 * swallow their own failures.
 */
interface BillingSignals
{
    /** A reservation was admitted. `backedByLease` is false for a fail-open admission. */
    public function allowed(string $org, string $meter, int $amount, bool $backedByLease): void;

    /** A reservation was refused. `$reason` is a short machine-readable cause. */
    public function denied(string $org, string $meter, int $amount, string $reason): void;

    /** A lease refill for (org, meter) completed, granting `$granted` units. */
    public function refilled(string $org, string $meter, int $granted): void;

    /** A usage flush completed, reporting `$organizations` organizations to billing. */
    public function reported(int $organizations): void;
}
