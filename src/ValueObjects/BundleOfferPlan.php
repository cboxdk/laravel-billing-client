<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One plan an offer sells: its price per period before and after the bundle, net of tax, and a
 * signed hosted `checkoutUrl` for this organization. A checkout that comes to nothing completes
 * without a payment.
 */
readonly class BundleOfferPlan
{
    public function __construct(
        public string $plan,
        public string $name,
        public int $priceBeforeMinor,
        public int $priceAfterMinor,
        public string $currency,
        public string $checkoutUrl,
        public string $interval = 'month',
        public string $priceBefore = '',
        public string $priceAfter = '',
    ) {}

    public function isFree(): bool
    {
        return $this->priceAfterMinor === 0;
    }

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'name' => $this->name,
            'interval' => $this->interval,
            'currency' => $this->currency,
            'price_before_minor' => $this->priceBeforeMinor,
            'price_after_minor' => $this->priceAfterMinor,
            'price_before' => $this->priceBefore,
            'price_after' => $this->priceAfter,
            'checkout_url' => $this->checkoutUrl,
        ];
    }
}
