<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A manual invoice to issue through `createInvoice()`: 1 to 200 lines, an optional order
 * reference for this document, an optional {@see InvoiceAppendix}, and flat string `metadata`
 * (at most 20 keys) that billing keeps opaque and returns on read and in the `invoice.issued`
 * webhook, so you can find your own record again.
 */
readonly class NewInvoice
{
    /**
     * @param  list<NewInvoiceLine>  $lines
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public array $lines,
        public ?string $orderReference = null,
        public ?InvoiceAppendix $appendix = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'lines' => array_map(static fn (NewInvoiceLine $line): array => $line->toArray(), $this->lines),
        ];

        if ($this->orderReference !== null) {
            $payload['order_reference'] = $this->orderReference;
        }

        if ($this->appendix !== null) {
            $payload['appendix'] = $this->appendix->toArray();
        }

        if ($this->metadata !== []) {
            $payload['metadata'] = $this->metadata;
        }

        return $payload;
    }
}
