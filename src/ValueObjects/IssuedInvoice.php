<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * An invoice in full, from `createInvoice()` or `invoice()`: everything an {@see Invoice} carries
 * plus billing's public `id` (`inv_…`), the `lines`, the `appendix` (the array you sent, null when none), your
 * `metadata`, a signed, time-limited `pdfUrl` and the `environment` it was issued in
 * (`live` or `sandbox`).
 *
 * Since 0.6.3 `id` is declared on {@see Invoice}; an issued invoice is always given one.
 */
readonly class IssuedInvoice extends Invoice
{
    /**
     * @param  list<IssuedInvoiceLine>  $lines
     * @param  array<string, mixed>|null  $appendix
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        string $number,
        ?DateTimeImmutable $date,
        int $amountMinor,
        string $currency,
        string $status,
        ?int $subtotalMinor,
        ?int $taxMinor,
        string $id,
        public array $lines,
        public ?array $appendix,
        public array $metadata,
        public ?string $pdfUrl,
        public string $environment,
    ) {
        parent::__construct($number, $date, $amountMinor, $currency, $status, $subtotalMinor, $taxMinor, $id);
    }
}
