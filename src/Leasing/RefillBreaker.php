<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Leasing;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The circuit breaker in front of a synchronous lease refill. When a refill finds Billing
 * unreachable the breaker opens for `refill_breaker` seconds, shared by every worker through the
 * cache store; while it is open no request tries Billing synchronously, it goes straight to the
 * failure policy (and its grace allowance) instead of waiting out a timeout. The background
 * refill still probes, and the first refill that gets an answer closes it again.
 */
class RefillBreaker
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $cooldown = 30,
        private readonly string $key = 'cbox-billing-client:refill-breaker',
    ) {}

    public function isOpen(): bool
    {
        return $this->cooldown > 0 && $this->cache->get($this->key) !== null;
    }

    public function trip(): void
    {
        if ($this->cooldown > 0) {
            $this->cache->put($this->key, 1, $this->cooldown);
        }
    }

    public function reset(): void
    {
        $this->cache->forget($this->key);
    }
}
