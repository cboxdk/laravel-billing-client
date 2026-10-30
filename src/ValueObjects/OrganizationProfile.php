<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * What billing stores about an organization, as it answers `PUT /api/v1/organizations/{org}`.
 *
 * `shared` is billing's `"account": "shared"`: another product owns the account, so the contact
 * and the address read null here and the upsert only filled what was empty. `taxIdValidated` is
 * whether the register confirmed `taxId` (null without a number, or from a server that does not
 * answer it). `taxIdSupported` is false for a server older than `tax_id` on the upsert.
 */
readonly class OrganizationProfile
{
    public function __construct(
        public string $id,
        public ?string $externalRef,
        public string $name,
        public ?string $billingEmail = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $subdivision = null,
        public ?string $country = null,
        public ?string $currency = null,
        public ?string $locale = null,
        public ?string $purchaserType = null,
        public ?string $eanNumber = null,
        public ?string $orderReference = null,
        public ?string $taxId = null,
        public ?bool $taxIdValidated = null,
        public bool $taxIdSupported = false,
        public bool $shared = false,
    ) {}
}
