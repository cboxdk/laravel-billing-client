<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\CheckoutSession;
use Cbox\Billing\Client\ValueObjects\PortalSession;

interface IdempotentSessionTransport
{
    public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null, ?string $idempotencyKey = null): CheckoutSession;

    public function createPortalSession(string $org, string $returnUrl, ?string $idempotencyKey = null): PortalSession;
}
