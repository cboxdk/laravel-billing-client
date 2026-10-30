<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * What an `unlock` offer saves on a subscription the organization already holds: its price per
 * period from `from`, before and after, net of tax.
 */
readonly class BundleSaving
{
    public function __construct(
        public int $priceBeforeMinor,
        public int $priceAfterMinor,
        public ?string $subscription = null,
        public ?string $product = null,
        public ?string $plan = null,
        public ?DateTimeImmutable $from = null,
        public string $priceBefore = '',
        public string $priceAfter = '',
    ) {}

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'product' => $this->product,
            'plan' => $this->plan,
            'from' => $this->from?->format(DateTimeInterface::ATOM),
            'price_before_minor' => $this->priceBeforeMinor,
            'price_after_minor' => $this->priceAfterMinor,
            'price_before' => $this->priceBefore,
            'price_after' => $this->priceAfter,
        ];
    }
}
