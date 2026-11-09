<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Leasing;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The small allowance a `last_known` client admits while Billing is unreachable and the last
 * known allowance does not cover a request: the organization's entitlement was never learned,
 * or its capped meter has no headroom left in what the client last knew. Without it, a Billing
 * outage refuses that traffic outright.
 *
 * It is `units` per (organization, meter) per `window` seconds, counted in the cache store and
 * shared by every worker; `meters` overrides `units` for one meter (a byte meter needs more
 * than a request meter). 0 turns grace off. A meter Billing last said is disabled never gets
 * grace. What grace admits is still committed into the usage ledger and reported to Billing,
 * which settles it once it is back: grace bounds the overage, it does not hide it.
 */
class OutageGrace
{
    /**
     * @param  array<string, int>  $meters  per-meter overrides of `$units`
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly int $units = 100,
        private readonly int $window = 3600,
        private readonly array $meters = [],
        private readonly string $prefix = 'cbox-billing-client:grace:',
    ) {}

    /** The grace a meter gets per window. */
    public function limit(string $meter): int
    {
        return max(0, $this->meters[$meter] ?? $this->units);
    }

    /** Take `$units` of grace; false (and nothing taken) when the window's grace is spent. */
    public function take(string $org, string $meter, int $units): bool
    {
        $limit = $this->limit($meter);

        if ($units <= 0 || $units > $limit) {
            return false;
        }

        $key = $this->key($org, $meter);
        $this->cache->add($key, 0, max(1, $this->window));
        $used = $this->cache->increment($key, $units);

        if (! is_int($used) || $used > $limit) {
            $this->cache->decrement($key, $units);

            return false;
        }

        return true;
    }

    /** Return grace a hold took but did not use. */
    public function giveBack(string $org, string $meter, int $units): void
    {
        if ($units > 0) {
            $this->cache->decrement($this->key($org, $meter), $units);
        }
    }

    public function remaining(string $org, string $meter): int
    {
        $used = $this->cache->get($this->key($org, $meter), 0);

        return max(0, $this->limit($meter) - (is_numeric($used) ? (int) $used : 0));
    }

    private function key(string $org, string $meter): string
    {
        return $this->prefix.$org.':'.$meter;
    }
}
