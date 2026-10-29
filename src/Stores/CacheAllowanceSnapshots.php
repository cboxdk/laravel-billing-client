<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Stores;

use Cbox\Billing\Client\Contracts\AllowanceSnapshots;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Illuminate\Contracts\Cache\Repository;

/**
 * {@see AllowanceSnapshots} backed by Laravel's cache, beside the lease counters. The
 * entitlement is stored as JSON; `drawn` and `admitted` move by atomic `increment` /
 * `decrement`, with the same decrement-and-compensate shape as the lease store, so a
 * race can only over-refuse.
 *
 * A counter is set outright in two cases only, both when billing has just told the
 * client something it can rely on: a short grant on a capped meter (billing has
 * nothing left, so `drawn` is the allowance), and a full grant that would take `drawn`
 * past the allowance (billing has started a new period, so only this grant counts).
 */
class CacheAllowanceSnapshots implements AllowanceSnapshots
{
    /**
     * @param  int  $refreshInterval  seconds between entitlement reads for one organization
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'cbox-billing-client:snapshot:',
        private readonly int $refreshInterval = 300,
    ) {}

    public function remember(Entitlements $entitlements): void
    {
        foreach ($entitlements->meters as $meter => $entitlement) {
            $this->cache->forever($this->key('entitlement', $entitlements->org, $meter), json_encode([
                'enabled' => $entitlement->enabled,
                'allowance' => $entitlement->allowance,
                'weight' => $entitlement->weight,
                'overage' => $entitlement->overage,
            ], JSON_THROW_ON_ERROR));
        }
    }

    public function claimRefresh(string $org): bool
    {
        return $this->cache->add($this->prefix.'refresh:'.$org, 1, max(1, $this->refreshInterval));
    }

    public function entitlement(string $org, string $meter): ?Entitlement
    {
        $raw = $this->cache->get($this->key('entitlement', $org, $meter));

        if (! is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        $allowance = $data['allowance'] ?? null;
        $weight = $data['weight'] ?? null;
        $overage = $data['overage'] ?? null;

        return new Entitlement(
            meter: $meter,
            enabled: ($data['enabled'] ?? false) === true,
            allowance: is_int($allowance) ? $allowance : null,
            weight: is_float($weight) || is_int($weight) ? (float) $weight : 1.0,
            overage: is_string($overage) ? $overage : 'block',
        );
    }

    public function recordLease(string $org, string $meter, int $requested, int $granted): void
    {
        $entitlement = $this->entitlement($org, $meter);
        $drawn = $this->key('drawn', $org, $meter);

        if ($entitlement === null || ! $entitlement->isCapped() || $entitlement->allowance === null) {
            if ($granted > 0) {
                $this->cache->increment($drawn, $granted);
            }

            return;
        }

        if ($granted < $requested) {
            // Billing grants all it has left, so a short grant leaves it with nothing.
            $this->cache->forever($drawn, $entitlement->allowance);

            return;
        }

        $after = $this->cache->increment($drawn, $granted);

        if (is_int($after) && $after > $entitlement->allowance) {
            // Billing could not have granted this within the period we counted: a new
            // period began. Count only what is known to be in it.
            $this->cache->forever($drawn, $granted);
            $this->cache->forever($this->key('admitted', $org, $meter), 0);
        }
    }

    public function headroom(string $org, string $meter): int
    {
        $entitlement = $this->entitlement($org, $meter);

        if ($entitlement === null || ! $entitlement->isCapped() || $entitlement->allowance === null) {
            return 0;
        }

        return max(0, $entitlement->allowance
            - $this->counter($this->key('drawn', $org, $meter))
            - $this->counter($this->key('admitted', $org, $meter)));
    }

    public function admit(string $org, string $meter, int $units): bool
    {
        $entitlement = $this->entitlement($org, $meter);

        if ($units <= 0 || $entitlement === null || ! $entitlement->enabled) {
            return false;
        }

        $key = $this->key('admitted', $org, $meter);
        $after = $this->cache->increment($key, $units);

        if (! $entitlement->isCapped() || $entitlement->allowance === null) {
            return true;
        }

        if (! is_int($after) || $entitlement->allowance - $this->counter($this->key('drawn', $org, $meter)) - $after < 0) {
            $this->cache->decrement($key, $units);

            return false;
        }

        return true;
    }

    public function giveBack(string $org, string $meter, int $units): void
    {
        if ($units > 0) {
            $this->cache->decrement($this->key('admitted', $org, $meter), $units);
        }
    }

    private function counter(string $key): int
    {
        $value = $this->cache->get($key, 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function key(string $kind, string $org, string $meter): string
    {
        return $this->prefix.$kind.':'.$org.':'.$meter;
    }
}
