<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A gateway payment intent from the management API — returned both when subscribing
 * requires an up-front charge (`POST /api/v1/subscriptions`) and when the embedded flow
 * charges directly (`POST /api/v1/payment-intents`). The service is gateway-agnostic, so
 * it relays the `gateway` identifier and `publishableKey` the front-end needs to load
 * the gateway's own element, the `clientSecret` that element confirms against, the
 * current `status` (e.g. `requires_confirmation`, or `requires_action` when strong
 * customer authentication is needed), and the `reference` by which the charge is later
 * reconciled (a webhook confirms settlement). The gateway JavaScript and any SCA
 * challenge are the product's responsibility; this SDK only relays these fields.
 */
readonly class PaymentIntent
{
    public function __construct(
        public string $gateway,
        public string $publishableKey,
        public ?string $clientSecret,
        public string $status,
        public string $reference,
    ) {}

    /**
     * True when the gateway element must run an extra step (e.g. an SCA challenge) before
     * the charge settles, so the front-end should surface the gateway's flow.
     */
    public function requiresAction(): bool
    {
        return $this->status === 'requires_action';
    }
}
