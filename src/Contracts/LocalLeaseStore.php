<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Stores\CacheLeaseStore;

/**
 * The node-local counter holding the leased balance per (org, meter). The default
 * ({@see CacheLeaseStore}) is backed by Laravel's cache
 * using only its atomic `increment` / `decrement` operations — no custom Lua — so it
 * works on the array driver in tests and on Redis/Memcached/database in production.
 *
 * {@see tryTake()} is a decrement-and-compensate: it decrements atomically and, if
 * the balance went negative, atomically adds it back and rejects. Both steps are
 * atomic, so under concurrency it can only ever over-reject, never over-grant —
 * exactly the safe direction for a hard limit. Single-node atomicity is all that is
 * required; cross-node correctness comes from pessimistic leasing, not this store.
 *
 * Move a counter only by DELTAS ({@see addLease()} / {@see giveBack()} /
 * {@see tryTake()}). Never `SET` it to a computed total (that would wipe in-flight
 * spend and let it be spent twice) and never clear it mid-period. A cold key seeds
 * from the zero baseline; counters expire only at the lease boundary, via TTL.
 */
interface LocalLeaseStore
{
    /** Remaining leased units available locally for (org, meter). */
    public function remaining(string $org, string $meter): int;

    /** Add freshly leased units to the local balance. */
    public function addLease(string $org, string $meter, int $granted): void;

    /**
     * Atomically take `amount` units if at least that many remain. Returns true on
     * success (balance decremented), false if insufficient (left unchanged).
     */
    public function tryTake(string $org, string $meter, int $amount): bool;

    /** Return `amount` units to the local balance (release / commit leftover). */
    public function giveBack(string $org, string $meter, int $amount): void;
}
