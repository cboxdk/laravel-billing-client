<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

/**
 * How the SDK resolves a request it can NEITHER satisfy from the local lease NOR
 * refill because billing is unreachable — an INFRASTRUCTURE fault, not an exhausted
 * allowance. This is a per-deployment knob, never a per-request decision.
 *
 *  - `Allow` — fail OPEN (the default): admit the request so a network blip does not
 *              throttle legitimate paid traffic. Usage is still buffered durably and
 *              reconciled from the cumulative ledger once billing is reachable again.
 *  - `Deny`  — fail CLOSED: refuse, for strict tenants that would rather block than
 *              admit un-leased usage during an outage.
 *
 * An EXHAUSTED allowance (billing granted zero) is SEMANTIC — a hard limit — and
 * always fails closed regardless of this policy.
 */
enum FailurePolicy: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    /** The deploy-safe default: preserve availability, reconcile after the fact. */
    public static function default(): self
    {
        return self::Allow;
    }

    /** Resolve from a config value, falling back to the fail-open default. */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::default())
            : self::default();
    }

    /** True when this policy admits an otherwise-indeterminate request. */
    public function admitsOnOutage(): bool
    {
        return $this === self::Allow;
    }
}
