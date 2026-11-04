<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\OneOffCheckoutItem;
use Cbox\Billing\Client\ValueObjects\OneOffCheckoutSession;

/** Additive contract: existing custom management transports keep compiling. */
interface OneOffCheckoutTransport
{
    /** @param list<OneOffCheckoutItem> $items */
    public function createOneOffCheckoutSession(string $org, array $items, string $returnUrl, string $reference, string $idempotencyKey, ?string $currency = null): OneOffCheckoutSession;
}
