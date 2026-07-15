<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Contracts\ReservationRegistry;

/**
 * A held-but-unsettled reservation recorded in the {@see ReservationRegistry} so its
 * lease-backed units can be reclaimed if the request that held them crashes before
 * commit or release. `holds` is the lease-backed units taken per meter, and
 * `expiresAt` is the millisecond epoch after which the sweeper returns those units to
 * their local slices — preventing a crashed request from leaking capacity for the
 * rest of the period.
 */
readonly class PendingReservation
{
    /**
     * @param  array<string, int>  $holds  lease-backed units taken per meter
     */
    public function __construct(
        public string $id,
        public string $org,
        public array $holds,
        public int $expiresAt,
    ) {}
}
