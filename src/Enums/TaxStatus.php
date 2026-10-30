<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

use Cbox\Billing\Client\ValueObjects\PriceTax;

/**
 * Whether the tax on a shown price is known ({@see PriceTax}).
 *
 *  - `Calculated`  — Cbox Tax priced it; the amounts are what the buyer pays.
 *  - `Pending`     — the place cannot be taxed from what billing knows (a US sale without a state).
 *  - `Unavailable` — Cbox Tax did not answer, or the billing environment is not connected to it.
 *
 * Only `Calculated` carries amounts. On the other two the price is net and must not be shown as
 * final.
 */
enum TaxStatus: string
{
    case Calculated = 'calculated';
    case Pending = 'pending';
    case Unavailable = 'unavailable';
}
