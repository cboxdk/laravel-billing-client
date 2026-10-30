<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * A dry-run of a plan change (`POST /api/v1/subscriptions/{org}/preview`) so a product
 * app can show the user exactly what switching plans costs before committing: the net
 * `dueNowMinor` charge, any prorated `creditMinor` applied, the `newRecurringMinor`
 * amount going forward, when the change takes `effectiveAt`, and the itemized `lines`.
 * All amounts are integer minor units. Since 0.4.2 it carries the coupon `discount` the
 * subscription keeps across the change (null when none, or from an older server). Since 0.4.4
 * it carries the bundle `consequences`: the benefits the change takes from the organization's
 * other subscriptions. Show each one's `message` before the customer confirms.
 *
 * Since 0.4.6: `dueNowMinor` includes tax; `dueNowNetMinor` and `dueNowTaxMinor` split it (null
 * from an older server). The `lines`, `creditMinor` and `newRecurringMinor` are net. A preview
 * (not a change) also carries `dueNowTax` (null when nothing is due) and `newRecurringTax`, the
 * tax on the new recurring price ({@see PriceTax}).
 */
readonly class ChangePreview
{
    /**
     * @param  list<PreviewLine>  $lines
     * @param  list<BundleConsequence>  $consequences
     */
    public function __construct(
        public int $dueNowMinor,
        public int $creditMinor,
        public int $newRecurringMinor,
        public ?DateTimeImmutable $effectiveAt,
        public array $lines,
        public ?SubscriptionDiscount $discount = null,
        public array $consequences = [],
        public ?int $dueNowNetMinor = null,
        public ?int $dueNowTaxMinor = null,
        public ?PriceTax $dueNowTax = null,
        public ?PriceTax $newRecurringTax = null,
    ) {}
}
