<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * Something the organization could add to save, ready to render. `kind` is:
 *
 *  - `add_product` — a product it does not hold that a bundle discounts now ("Add Cbox Tax, free
 *    with your Billing plan"); `plans` are that product's plans with prices and checkout links.
 *  - `unlock` — it holds the discounted product but not the one that earns the discount;
 *    `plans` are the plans of `product` to add, and `saves` what it would save.
 *  - `multi_product` — the next multi-product tier: hold `minPaidProducts` paid products (it holds
 *    `paidProductsHeld`) and save `percentOff` on each; `productsToAdd` lists the candidates.
 *
 * Show `headline` as the call to action and `description` underneath.
 */
readonly class BundleOffer
{
    /**
     * @param  array<string, int>|null  $amountOff  currency → minor units, for a fixed-amount rule
     * @param  list<BundleOfferPlan>  $plans
     * @param  array<string, string>  $productsToAdd  product key → name
     */
    public function __construct(
        public string $rule,
        public string $kind,
        public string $headline,
        public string $label = '',
        public string $description = '',
        public string $name = '',
        public ?int $percentOff = null,
        public ?array $amountOff = null,
        public ?string $product = null,
        public ?string $productName = null,
        public ?string $discountedProduct = null,
        public array $plans = [],
        public ?BundleSaving $saves = null,
        public ?int $minPaidProducts = null,
        public ?int $paidProductsHeld = null,
        public ?int $productsNeeded = null,
        public ?int $currentPercentOff = null,
        public array $productsToAdd = [],
    ) {}

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        $out = [
            'rule' => $this->rule,
            'kind' => $this->kind,
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'headline' => $this->headline,
            'percent_off' => $this->percentOff,
            'amount_off' => $this->amountOff,
        ];

        if ($this->kind === 'multi_product') {
            $products = [];

            foreach ($this->productsToAdd as $key => $name) {
                $products[] = ['product' => $key, 'name' => $name];
            }

            return $out + [
                'min_paid_products' => $this->minPaidProducts,
                'paid_products_held' => $this->paidProductsHeld,
                'products_needed' => $this->productsNeeded,
                'current_percent_off' => $this->currentPercentOff,
                'products_to_add' => $products,
            ];
        }

        return $out + [
            'product' => $this->product,
            'product_name' => $this->productName,
            'discounted_product' => $this->discountedProduct,
            'plans' => array_map(static fn (BundleOfferPlan $plan): array => $plan->toArray(), $this->plans),
            'saves' => $this->saves?->toArray(),
        ];
    }
}
