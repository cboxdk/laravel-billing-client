<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One line of an {@see IssuedInvoice} as billing stored it. `quantity` is the decimal string
 * (`12.500`); amounts are integer minor units: `netMinor` before tax, `taxMinor`, and
 * `amountMinor` with tax.
 */
readonly class IssuedInvoiceLine
{
    public function __construct(
        public string $description,
        public string $quantity,
        public ?string $unit,
        public int $unitAmountMinor,
        public int $netMinor,
        public int $taxMinor,
        public int $amountMinor,
    ) {}
}
