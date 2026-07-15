<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A subscribable plan from the management API (`GET /api/v1/plans`): its stable `key`,
 * display `name`, recurring `priceMinor` in the smallest currency unit (e.g. cents)
 * under `currency`, the billing `interval` (e.g. `month`/`year`), and the set of
 * meter `entitlements` the plan grants. Prices are integer minor units to avoid
 * float rounding.
 */
readonly class Plan
{
    /**
     * @param  list<Entitlement>  $entitlements
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $priceMinor,
        public string $currency,
        public string $interval,
        public array $entitlements,
    ) {}

    /** The entitlement this plan grants for `$meter`, or `null` when it grants none. */
    public function entitlement(string $meter): ?Entitlement
    {
        foreach ($this->entitlements as $entitlement) {
            if ($entitlement->meter === $meter) {
                return $entitlement;
            }
        }

        return null;
    }
}
