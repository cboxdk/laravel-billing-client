<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * Everything an app shows about bundles for one organization
 * (`GET /api/v1/organizations/{org}/bundles`): the benefits on its subscriptions (`active`) and
 * what it could add to save (`offers`), with copy in `locale` and prices in `currency`. `product`
 * is the product the answer was narrowed to (a product-bound token's own).
 */
readonly class OrganizationBundles
{
    /**
     * @param  list<ActiveBundle>  $active
     * @param  list<BundleOffer>  $offers
     */
    public function __construct(
        public string $organization,
        public string $currency,
        public string $locale,
        public array $active = [],
        public array $offers = [],
        public ?string $organizationRef = null,
        public ?string $product = null,
    ) {}

    /** The benefit on the organization's subscription to `$product`, if any. */
    public function activeFor(string $product): ?ActiveBundle
    {
        foreach ($this->active as $active) {
            if ($active->product === $product) {
                return $active;
            }
        }

        return null;
    }

    /**
     * The offers of one kind (`add_product`, `unlock`, `multi_product`).
     *
     * @return list<BundleOffer>
     */
    public function offersOfKind(string $kind): array
    {
        return array_values(array_filter($this->offers, static fn (BundleOffer $offer): bool => $offer->kind === $kind));
    }

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        return [
            'organization' => $this->organization,
            'organization_ref' => $this->organizationRef,
            'product' => $this->product,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'active' => array_map(static fn (ActiveBundle $active): array => $active->toArray(), $this->active),
            'offers' => array_map(static fn (BundleOffer $offer): array => $offer->toArray(), $this->offers),
        ];
    }
}
