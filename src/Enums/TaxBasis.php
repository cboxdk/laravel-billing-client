<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

/**
 * Who billing priced a shown price's tax for.
 *
 *  - `Customer`      — the billing account you named (`org`), with its country, business or consumer
 *                      status, a VAT number billing validated, and any exemption certificates.
 *  - `Country`       — a consumer in the country you named (`country`), or the account had none.
 *  - `SellerDefault` — nobody in particular: a consumer in the seller's own country.
 */
enum TaxBasis: string
{
    case Customer = 'customer';
    case Country = 'country';
    case SellerDefault = 'seller_default';
}
