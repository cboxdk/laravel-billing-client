<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A payment intent returned when subscribing requires an up-front charge
 * (`POST /api/v1/subscriptions`): its `id`, current `status`, and an optional
 * `clientSecret` the product app's front-end uses to confirm the payment. Absent when
 * the change is fully covered by credit and nothing is due now.
 */
readonly class PaymentIntent
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $clientSecret = null,
    ) {}
}
