<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * An organization's entitlement set, keyed by meter — the decoded
 * `GET /api/v1/entitlements/{org}` response. Deny-by-default: an unknown meter has
 * no entitlement and {@see enabled()} reports false, so a caller that gates on it
 * treats an unlisted meter as not entitled rather than silently trusted.
 */
readonly class Entitlements
{
    /**
     * @param  array<string, Entitlement>  $meters
     */
    public function __construct(
        public string $org,
        public array $meters,
    ) {}

    public function for(string $meter): ?Entitlement
    {
        return $this->meters[$meter] ?? null;
    }

    /** Deny-by-default: unknown or disabled meters are not enabled. */
    public function enabled(string $meter): bool
    {
        $entitlement = $this->for($meter);

        return $entitlement !== null && $entitlement->enabled;
    }

    /**
     * Weighted billable cost of `$units` raw units on `$meter`, applying the meter's
     * entitlement weight. Deny-by-default: an unknown meter has no entitlement and
     * therefore costs nothing here.
     */
    public function cost(string $meter, int $units): float
    {
        return $this->for($meter)?->cost($units) ?? 0.0;
    }
}
