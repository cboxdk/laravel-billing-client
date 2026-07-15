<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

/**
 * The three-way result of an authoritative REMOTE reservation
 * (`POST /api/v1/reserve`) — the synchronous round-trip path, distinct from the
 * local leased hot path. It mirrors the engine's enforcement outcomes so callers and
 * telemetry see WHICH path fired rather than collapsing to a boolean.
 *
 *  - `Allowed`       — billing reserved the estimate; carries a `reservation_id`.
 *  - `Denied`        — a SEMANTIC refusal (exhausted allowance / disabled meter).
 *  - `Indeterminate` — billing could not decide (a dependency was down); the caller
 *                      resolves it with its {@see FailurePolicy}.
 */
enum ReserveOutcome: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Indeterminate = 'indeterminate';

    /** Deny-by-default: an unknown/absent wire value is treated as indeterminate. */
    public static function fromWire(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::Indeterminate)
            : self::Indeterminate;
    }
}
