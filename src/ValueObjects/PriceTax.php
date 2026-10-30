<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Enums\TaxBasis;
use Cbox\Billing\Client\Enums\TaxStatus;

/**
 * The tax on a price billing shows (a plan, a subscription's recurring charge, a plan change),
 * calculated by Cbox Tax the way the buyer's invoice will be. Read from the `tax` object of
 * `GET /api/v1/plans` and friends.
 *
 * Show {@see $totalMinor}, the amount the buyer pays, rather than taxing a net price yourself.
 * Only a {@see TaxStatus::Calculated} answer carries amounts: on `Pending` and `Unavailable` the
 * price is net and the tax is unknown, so say so and never show the net price as final.
 *
 * `treatment` is Cbox Tax's (`standard`, `reverse_charge`, `zero_rated`, `exempt`,
 * `not_registered`, …) and `rate` the percentage applied as a decimal string (`"25"`, `"25.5"`),
 * null where none applies. `customer` is `business` or `consumer`. `reason` says why there are no
 * amounts (`place_not_taxable`, `tax_calculation_unavailable`, `tax_service_not_connected`,
 * `seller_not_configured`).
 *
 * Every field after `basis` is optional, so a test can build one cheaply.
 */
readonly class PriceTax
{
    public function __construct(
        public TaxStatus $status,
        public TaxBasis $basis,
        public ?string $country = null,
        public string $customer = 'consumer',
        public ?string $treatment = null,
        public ?string $rate = null,
        public ?int $taxMinor = null,
        public ?int $totalMinor = null,
        public ?string $reason = null,
    ) {}

    /** Whether the amounts are known: Cbox Tax priced this for the buyer. */
    public function isCalculated(): bool
    {
        return $this->status === TaxStatus::Calculated && $this->totalMinor !== null;
    }

    /** An EU business with a validated VAT number in another member state: no tax charged, the buyer accounts for it. */
    public function isReverseCharge(): bool
    {
        return $this->treatment === 'reverse_charge';
    }

    /** Whether tax is added to the price: a calculated answer with tax above zero. */
    public function chargesTax(): bool
    {
        return $this->isCalculated() && ($this->taxMinor ?? 0) > 0;
    }

    /**
     * The same answer for another price: `$totalMinor` and `$taxMinor` replaced. Used by a fake
     * that prices from a template.
     */
    public function withAmounts(?int $taxMinor, ?int $totalMinor): self
    {
        return new self($this->status, $this->basis, $this->country, $this->customer, $this->treatment, $this->rate, $taxMinor, $totalMinor, $this->reason);
    }

    /**
     * The wire shape, as billing sends it.
     *
     * @return array{status: string, basis: string, country: string|null, customer: string, treatment: string|null, rate: string|null, tax_minor: int|null, total_minor: int|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'basis' => $this->basis->value,
            'country' => $this->country,
            'customer' => $this->customer,
            'treatment' => $this->treatment,
            'rate' => $this->rate,
            'tax_minor' => $this->taxMinor,
            'total_minor' => $this->totalMinor,
            'reason' => $this->reason,
        ];
    }
}
