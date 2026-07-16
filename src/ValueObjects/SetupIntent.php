<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A gateway setup intent from the management API (`POST /api/v1/setup-intents`) for the
 * embedded payment flow: it lets the product app collect and store a payment method
 * without an immediate charge. The service is gateway-agnostic, so it returns the
 * `gateway` identifier and `publishableKey` the front-end needs to load the gateway's
 * own element, the `clientSecret` that element confirms against, the current `status`
 * (e.g. `requires_action` when strong customer authentication is needed), and the
 * `reference` by which the intent is later reconciled. The gateway JavaScript and any
 * SCA challenge are the product's responsibility; this SDK only relays these fields.
 */
readonly class SetupIntent
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
     * the payment method is stored, so the front-end should surface the gateway's flow.
     */
    public function requiresAction(): bool
    {
        return $this->status === 'requires_action';
    }
}
