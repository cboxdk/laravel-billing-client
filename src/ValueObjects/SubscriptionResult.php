<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * The result of creating a subscription (`POST /v1/subscriptions`): the resulting
 * {@see Subscription} and, when an up-front charge is required, the
 * {@see PaymentIntent} the front-end must confirm. A null `paymentIntent` means the
 * subscription is active with nothing to confirm. Billing answers it null today; the field is
 * reserved.
 *
 * Since 0.6.3 `invoice` is the opening invoice and what its off-session charge did
 * ({@see ChargedInvoice}). Null when nothing was billed (a trial, billing details still missing)
 * and from a server that does not send it. When {@see ChargedInvoice::isPending()}, send the
 * customer to the portal's pending-payment page for that invoice.
 */
readonly class SubscriptionResult
{
    public function __construct(
        public Subscription $subscription,
        public ?PaymentIntent $paymentIntent = null,
        public ?ChargedInvoice $invoice = null,
    ) {}

    /** True when the caller must confirm a payment before the subscription is settled. */
    public function requiresPayment(): bool
    {
        return $this->paymentIntent !== null;
    }
}
