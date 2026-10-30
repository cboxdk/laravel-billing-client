<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * A historical invoice from the management API (`GET /api/v1/invoices/{org}`): its
 * `number`, issue `date`, `amountMinor` in the smallest unit of `currency`, and
 * `status` (e.g. `paid`, `open`, `void`). Amounts are integer minor units.
 *
 * `amountMinor` is the total, tax included. Since 0.4.6 `subtotalMinor` is the net and `taxMinor`
 * the tax (null from an older server).
 */
readonly class Invoice
{
    public function __construct(
        public string $number,
        public ?DateTimeImmutable $date,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public ?int $subtotalMinor = null,
        public ?int $taxMinor = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
