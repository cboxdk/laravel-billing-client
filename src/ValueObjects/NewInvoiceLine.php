<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One line of a manual invoice you ask billing to issue ({@see NewInvoice}).
 *
 * `quantity` is the decimal string the server validates (at most three fractional digits, for
 * example `12.500`); the client does not parse it. `unitAmountMinor` is a positive integer in the
 * account's billing currency. `unit` (at most 12 characters) prints after the quantity;
 * `category` is a tax category key the Cbox Tax API knows (billing's default when null).
 */
readonly class NewInvoiceLine
{
    public function __construct(
        public string $description,
        public string $quantity,
        public int $unitAmountMinor,
        public ?string $unit = null,
        public ?string $category = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_amount_minor' => $this->unitAmountMinor,
            'category' => $this->category,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
