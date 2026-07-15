<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * The billing-side policy for one `(org, meter)`, as read from
 * `GET /api/v1/entitlements/{org}`: whether the meter is `enabled`, its included
 * `allowance`, the `weight` used to convert raw units into billable cost, and the
 * `overage` behaviour past the allowance (`"block"` = hard limit, `"bill"` = paid
 * overage). The overage string is kept as-is off the wire so a new behaviour added
 * by billing does not break older clients.
 */
readonly class Entitlement
{
    public function __construct(
        public string $meter,
        public bool $enabled,
        public int $allowance,
        public float $weight,
        public string $overage,
    ) {}

    /** True when exceeding the allowance is a hard limit rather than paid overage. */
    public function blocksOnOverage(): bool
    {
        return $this->overage === 'block';
    }

    /**
     * Weighted billable cost of `$units` raw units on this meter: `units × weight`.
     * The weight converts a raw metered quantity (requests, tokens, bytes) into the
     * meter's billable cost unit. Non-positive usage costs nothing.
     */
    public function cost(int $units): float
    {
        return $units <= 0 ? 0.0 : $units * $this->weight;
    }
}
