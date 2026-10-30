<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * What a cancel or a plan change does to ANOTHER subscription's bundle benefit: from `from` (that
 * subscription's first period without it) it loses `before` (`lost`) or drops to `after`, and its
 * price per period, net of tax and of any coupon, goes from `priceBeforeMinor` to
 * `priceAfterMinor`. `message` is the sentence to show the customer before they confirm.
 */
readonly class BundleConsequence
{
    public function __construct(
        public bool $lost,
        public DateTimeImmutable $from,
        public string $message,
        public int $priceBeforeMinor,
        public int $priceAfterMinor,
        public string $currency,
        public ?string $subscription = null,
        public ?string $product = null,
        public ?string $productName = null,
        public ?string $plan = null,
        public ?BundleBenefit $before = null,
        public ?BundleBenefit $after = null,
        public string $priceBefore = '',
        public string $priceAfter = '',
    ) {}

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'product' => $this->product,
            'product_name' => $this->productName,
            'plan' => $this->plan,
            'lost' => $this->lost,
            'from' => $this->from->format(DateTimeInterface::ATOM),
            'before' => $this->before?->toArray(),
            'after' => $this->after?->toArray(),
            'currency' => $this->currency,
            'price_before_minor' => $this->priceBeforeMinor,
            'price_after_minor' => $this->priceAfterMinor,
            'price_before' => $this->priceBefore,
            'price_after' => $this->priceAfter,
            'message' => $this->message,
        ];
    }
}
