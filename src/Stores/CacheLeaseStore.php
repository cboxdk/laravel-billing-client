<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Stores;

use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * {@see LocalLeaseStore} backed by Laravel's cache — the default node-local lease
 * counter. It uses only the cache's own atomic `increment` / `decrement` operations
 * (no custom Lua), so it works unchanged on the array driver in tests and on
 * Redis/Memcached/database in production.
 *
 * {@see tryTake()} is a decrement-and-compensate: decrement atomically, and if the
 * balance went negative, atomically add it back and reject. Both steps are atomic, so
 * under concurrency this can only ever over-reject, never over-grant — the safe
 * direction for a hard limit.
 *
 * Every mutator is an atomic DELTA: `increment` on a lease/give-back, `decrement` on
 * a take. Nothing ever `SET`s a counter to a computed total (which would wipe
 * in-flight spend and let it be spent twice) and nothing clears a counter mid-period.
 * A cold key seeds from the zero baseline — `increment` starts a missing key at 0 and
 * the reads here fall open to 0 — so the derived balance fails open to billing rather
 * than erroring. If you extend this class, keep to `get`/`increment`/`decrement`;
 * introducing `put`/`forever`/`forget`/`flush` reintroduces the double-spend this
 * derivation prevents.
 */
class CacheLeaseStore implements LocalLeaseStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'cbox-billing-client:lease:',
    ) {}

    public function remaining(string $org, string $meter): int
    {
        // `is_numeric` also covers the raw integer string a Redis INCRBY leaves,
        // which `get()` would otherwise not round-trip.
        $value = $this->cache->get($this->key($org, $meter), 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function addLease(string $org, string $meter, int $granted): void
    {
        if ($granted <= 0) {
            return;
        }

        $this->cache->increment($this->key($org, $meter), $granted);
    }

    public function tryTake(string $org, string $meter, int $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $key = $this->key($org, $meter);
        $after = $this->cache->decrement($key, $amount);

        // A store that cannot decrement (missing / non-numeric) yields no take.
        if (! is_int($after)) {
            return false;
        }

        if ($after < 0) {
            $this->cache->increment($key, $amount);

            return false;
        }

        return true;
    }

    public function giveBack(string $org, string $meter, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->cache->increment($this->key($org, $meter), $amount);
    }

    private function key(string $org, string $meter): string
    {
        return $this->prefix.$org.':'.$meter;
    }
}
