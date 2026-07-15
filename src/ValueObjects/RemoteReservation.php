<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Enums\ReserveOutcome;

/**
 * The decision returned by an authoritative remote reservation
 * (`POST /api/v1/reserve`). On `Allowed` it carries the billing-side
 * `reservationId` to later commit; on `Denied`/`Indeterminate` it may carry a
 * human-readable `reason`.
 */
readonly class RemoteReservation
{
    public function __construct(
        public ReserveOutcome $outcome,
        public ?string $reservationId = null,
        public ?string $reason = null,
    ) {}

    public function allowed(): bool
    {
        return $this->outcome === ReserveOutcome::Allowed;
    }
}
