<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A subscribable plan from the management API (`GET /api/v1/plans`): its stable `key`,
 * display `name`, recurring `priceMinor` in the smallest currency unit (e.g. cents)
 * under `currency`, the billing `interval` (e.g. `month`/`year`), the set of
 * meter `entitlements` the plan grants, and its boolean/config `features`. Prices are
 * integer minor units to avoid float rounding.
 *
 * `features` is the plan's `{key: value}` map. The server lists every feature any plan of the
 * same product grants, deny-by-default: `false` when this plan does not grant it, `true` for a
 * granted boolean feature, the `int`/`string` value of a granted config feature. A server that
 * predates features sends none, and the map is empty.
 *
 * `product` is the key of the product the plan belongs to, and `taxInclusive` whether the seller
 * quotes the price including tax (`false`: net, tax is added at invoicing). Both are `null` when
 * the server does not say.
 *
 * The constructor is cheap, and every field after `entitlements` is optional, so a test can
 * build a plan without the newer fields.
 */
readonly class Plan
{
    /**
     * @param  list<Entitlement>  $entitlements
     * @param  array<string, int|string|bool|null>  $features
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $priceMinor,
        public string $currency,
        public string $interval,
        public array $entitlements,
        public array $features = [],
        public ?string $product = null,
        public ?bool $taxInclusive = null,
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

    /**
     * The plan's value for feature `$key`: `false` when the plan does not grant it, `true` for a
     * granted boolean feature, the value of a granted config feature, and `null` when the server
     * does not list the feature at all.
     */
    public function feature(string $key): int|string|bool|null
    {
        return $this->features[$key] ?? null;
    }

    /** Whether the plan grants feature `$key`. An unlisted feature is not granted (deny-by-default). */
    public function hasFeature(string $key): bool
    {
        $value = $this->feature($key);

        return $value !== null && $value !== false;
    }

    /**
     * Feature `$key` as an integer limit: the value of a granted integer config feature, `0` when
     * the plan does not grant it, and `null` when it is unlisted or carries no integer.
     */
    public function featureInt(string $key): ?int
    {
        $value = $this->feature($key);

        return match (true) {
            is_int($value) => $value,
            $value === false => 0,
            default => null,
        };
    }
}
