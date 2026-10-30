<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A cross-product bundle benefit on one plan price, as Cbox Billing presents it: the catalog
 * `rule` key, its `kind` (`product` or `multi_product`), ready-to-render text in the requested
 * language (`label`, `description`, `text` such as "100% off"), the discount (`percentOff` or
 * `amountOffMinor`), and the plan price per period before and after, net of tax, as integer minor
 * units and preformatted strings. `requires` is the product key that earns it (a product rule),
 * null for a multi-product rule or where the server does not say.
 *
 * Show `label` next to the plan ("Included with Cbox Billing") and `priceAfter` as the price.
 */
readonly class BundleBenefit
{
    public function __construct(
        public string $rule,
        public string $kind,
        public string $label,
        public int $priceBeforeMinor,
        public int $priceAfterMinor,
        public string $currency,
        public string $name = '',
        public string $description = '',
        public string $text = '',
        public ?int $percentOff = null,
        public ?int $amountOffMinor = null,
        public string $priceBefore = '',
        public string $priceAfter = '',
        public ?string $requires = null,
    ) {}

    /** True when the benefit takes the whole price: the plan is included. */
    public function isFree(): bool
    {
        return $this->priceAfterMinor === 0;
    }

    /** True for a percentage benefit, false for a fixed amount off. */
    public function isPercentage(): bool
    {
        return $this->percentOff !== null;
    }

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'kind' => $this->kind,
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'percent_off' => $this->percentOff,
            'amount_off_minor' => $this->amountOffMinor,
            'currency' => $this->currency,
            'price_before_minor' => $this->priceBeforeMinor,
            'price_after_minor' => $this->priceAfterMinor,
            'price_before' => $this->priceBefore,
            'price_after' => $this->priceAfter,
            'text' => $this->text,
            'requires' => $this->requires,
        ];
    }
}
