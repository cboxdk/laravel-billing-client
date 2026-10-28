<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Stores\ArrayReservationRegistry;
use Cbox\Billing\Client\Stores\CacheReservationRegistry;
use Cbox\Billing\Client\ValueObjects\PendingReservation;

/**
 * A durable register of held-but-unsettled reservations and their lease-backed units,
 * so a hold whose request crashes before commit/release does not strand its slice for
 * the rest of the period. {@see open()} records a hold with a TTL; {@see close()}
 * removes it on commit/release; {@see expired()} yields every hold past its TTL for
 * the sweeper to return to the local lease.
 *
 * The default ({@see CacheReservationRegistry}) is cache-backed and lives beside the
 * lease counters, so both survive a crashed request; tests use
 * {@see ArrayReservationRegistry}. Point it at a persistent cache in production so a
 * process restart cannot lose a pending hold before it is swept.
 */
interface ReservationRegistry
{
    /**
     * Record a held reservation and the lease-backed units it took per meter, expiring
     * at `$expiresAt` (millisecond epoch). A hold with no lease-backed units need not
     * be recorded — there is nothing to reclaim.
     *
     * @param  array<string, int>  $holds  lease-backed units taken per meter
     */
    public function open(string $id, string $org, array $holds, int $expiresAt): void;

    /**
     * Close a held reservation. Returns true only for the ONE call that closed an open
     * hold; false when it is unknown or already closed (swept, settled, or closed by a
     * concurrent caller). The closer owns the give-back of its units, so this is what stops
     * a hold being credited back twice. Implementations must make the claim atomic.
     */
    public function close(string $id): bool;

    /**
     * Every held reservation whose TTL has passed at `$now` (millisecond epoch), for
     * the sweeper to reclaim.
     *
     * @return list<PendingReservation>
     */
    public function expired(int $now): array;
}
