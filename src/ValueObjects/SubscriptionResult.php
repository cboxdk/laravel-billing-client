<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * The result of creating a subscription (`POST /api/v1/subscriptions`): the resulting
 * {@see Subscription} and, when an up-front charge is required, the
 * {@see PaymentIntent} the front-end must confirm. A null `paymentIntent` means the
 * subscription is active with nothing to confirm.
 */
readonly class SubscriptionResult
{
    public function __construct(
        public Subscription $subscription,
        public ?PaymentIntent $paymentIntent = null,
    ) {}

    /** True when the caller must confirm a payment before the subscription is settled. */
    public function requiresPayment(): bool
    {
        return $this->paymentIntent !== null;
    }
}
