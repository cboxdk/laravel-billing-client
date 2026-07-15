<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\BillingClient;

/**
 * A held slice of the local lease, returned by {@see BillingClient::reserve()} and
 * settled by commit/release. `amount` is the number of units held.
 *
 * `backedByLease` records whether the reservation actually decremented the local
 * lease. It is `false` only when the request was admitted under a fail-OPEN outage
 * (billing was unreachable and no local units were available): such a reservation
 * still buffers usage on commit for later reconciliation, but must NOT give units
 * back to the lease on commit/release — it never took any.
 */
readonly class Reservation
{
    public function __construct(
        public string $id,
        public string $org,
        public string $meter,
        public int $amount,
        public bool $backedByLease = true,
    ) {}
}
