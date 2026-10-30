<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * What a subscription costs per period as its invoice bills it now (`recurring` on
 * `GET /api/v1/subscriptions/{org}`): `netMinor` is the plan for its seats less the bundle benefit
 * and the coupon, without add-ons or metered usage, and `tax` the tax on it for the organization.
 * Show `tax->totalMinor` when the tax is calculated.
 */
readonly class RecurringPrice
{
    public function __construct(
        public int $netMinor,
        public string $currency,
        public ?PriceTax $tax = null,
    ) {}

    /** What the customer pays per period, tax included; null when the tax is not known. */
    public function totalMinor(): ?int
    {
        return $this->tax?->isCalculated() === true ? $this->tax->totalMinor : null;
    }
}
