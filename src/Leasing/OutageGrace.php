<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Leasing;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

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

    /**
     * Take `$units` of grace. Returns the window it was taken from (keep it with the hold, to
     * give unused grace back to that same window), or null when the window's grace is spent.
     */
    public function take(string $org, string $meter, int $units): ?int
    {
        $limit = $this->limit($meter);

        if ($units <= 0 || $units > $limit) {
            return null;
        }

        $window = $this->currentWindow();
        $key = $this->key($org, $meter, $window);
        $this->cache->add($key, 0, max(1, $this->window));
        $used = $this->cache->increment($key, $units);

        if (! is_int($used) || $used > $limit) {
            $this->cache->decrement($key, $units);

            return null;
        }

        return $window;
    }

    /**
     * Return grace a hold took from `$window` but did not use. Only to that window, and only
     * while it still exists: a decrement on an expired key would recreate it without a TTL.
     * A hold that outlived its window gives back nothing.
     */
    public function giveBack(string $org, string $meter, int $units, int $window): void
    {
        $key = $this->key($org, $meter, $window);

        if ($units > 0 && $this->cache->get($key) !== null) {
            $this->cache->decrement($key, $units);
        }
    }

    public function remaining(string $org, string $meter): int
    {
        $used = $this->cache->get($this->key($org, $meter, $this->currentWindow()), 0);

        return max(0, $this->limit($meter) - (is_numeric($used) ? (int) $used : 0));
    }

    private function currentWindow(): int
    {
        return intdiv(Carbon::now()->getTimestamp(), max(1, $this->window));
    }

    /** One key per window, so a counter that lost its TTL can never outlive its window. */
    private function key(string $org, string $meter, int $window): string
    {
        return $this->prefix.$org.':'.$meter.':'.$window;
    }
}
