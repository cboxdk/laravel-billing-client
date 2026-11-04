<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

readonly class OneOffQuote
{
    public function __construct(
        public string $id, public string $organization, public string $reference, public string $product,
        public int $quantity, public string $currency, public int $netMinor, public int $taxMinor,
        public int $totalMinor, public string $priceVersion, public DateTimeImmutable $expiresAt,
        public string $status, public ?string $invoice, public string $paymentStatus,
    ) {}
}
