<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

/**
 * How the SDK resolves a request it can NEITHER satisfy from the local lease NOR
 * refill because billing is unreachable — an INFRASTRUCTURE fault, not an exhausted
 * allowance. This is a per-deployment knob, never a per-request decision.
 *
 *  - `LastKnown` — enforce from the last known entitlement (the default since 0.4.1):
 *                  admit up to the allowance minus what this client has drawn, then
 *                  refuse on a hard-limit meter. A `bill`-overage or unlimited meter
 *                  keeps admitting. With no known entitlement it refuses, as `Deny`.
 *  - `Allow`     — fail OPEN: admit every request so a network blip never throttles
 *                  traffic, even past the allowance.
 *  - `Deny`      — fail CLOSED: refuse as soon as the local lease is spent.
 *
 * Under every policy, usage is still buffered durably and reconciled from the cumulative
 * ledger once billing is reachable again. An EXHAUSTED allowance (billing granted zero)
 * is SEMANTIC — a hard limit — and always fails closed regardless of this policy.
 */
enum FailurePolicy: string
{
    case LastKnown = 'last_known';
    case Allow = 'allow';
    case Deny = 'deny';

    /** The default: keep serving during an outage, but never past the known allowance. */
    public static function default(): self
    {
        return self::LastKnown;
    }

    /** Resolve from a config value, falling back to the {@see default()}. */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::default())
            : self::default();
    }

    /** True when this policy admits every otherwise-indeterminate request (`Allow` only). */
    public function admitsOnOutage(): bool
    {
        return $this === self::Allow;
    }

    /** True when an outage is decided from the last known entitlement. */
    public function enforcesLastKnown(): bool
    {
        return $this === self::LastKnown;
    }
}
