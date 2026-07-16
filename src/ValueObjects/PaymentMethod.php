<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A stored payment method from the management API (`GET /api/v1/payment-methods/{org}`):
 * its opaque `id`, the card `brand`, the `last4`, the expiry `expMonth`/`expYear`, and
 * whether it `isDefault` for the organization. No full number, CVC, or other sensitive
 * detail ever crosses this seam — only the gateway-safe descriptor the product app shows
 * the user.
 */
readonly class PaymentMethod
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
        public bool $isDefault,
    ) {}
}
