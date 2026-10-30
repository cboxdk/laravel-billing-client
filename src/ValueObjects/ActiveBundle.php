<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/** A bundle benefit on one of the organization's subscriptions: which one, and its {@see SubscriptionBundle}. */
readonly class ActiveBundle
{
    public function __construct(
        public SubscriptionBundle $bundle,
        public ?string $subscription = null,
        public ?string $product = null,
        public ?string $plan = null,
    ) {}

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        return ['subscription' => $this->subscription, 'product' => $this->product, 'plan' => $this->plan] + $this->bundle->toArray();
    }
}
