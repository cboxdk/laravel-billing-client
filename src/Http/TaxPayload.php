<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Enums\TaxBasis;
use Cbox\Billing\Client\Enums\TaxStatus;
use Cbox\Billing\Client\ValueObjects\PriceTax;
use Cbox\Billing\Client\ValueObjects\RecurringPrice;

/**
 * Reads the management API's tax shapes (`tax`, `recurring`, `due_now_tax`, `new_recurring_tax`)
 * into value objects, for {@see HttpManagementTransport}. An absent block (an older server) reads as
 * null. A block whose status or basis this client does not know reads as null too: an answer it
 * cannot interpret is no answer, never a guessed price.
 */
class TaxPayload
{
    public static function priceTax(mixed $raw): ?PriceTax
    {
        if (! is_array($raw)) {
            return null;
        }

        $status = is_string($raw['status'] ?? null) ? TaxStatus::tryFrom($raw['status']) : null;
        $basis = is_string($raw['basis'] ?? null) ? TaxBasis::tryFrom($raw['basis']) : null;

        if ($status === null || $basis === null) {
            return null;
        }

        $calculated = $status === TaxStatus::Calculated;
        $tax = $calculated ? self::int($raw['tax_minor'] ?? null) : null;
        $total = $calculated ? self::int($raw['total_minor'] ?? null) : null;

        // A calculated answer without its amounts cannot be shown as a price.
        if ($calculated && ($tax === null || $total === null)) {
            return null;
        }

        return new PriceTax(
            status: $status,
            basis: $basis,
            country: self::string($raw['country'] ?? null),
            customer: ($raw['customer'] ?? null) === 'business' ? 'business' : 'consumer',
            treatment: self::string($raw['treatment'] ?? null),
            rate: self::string($raw['rate'] ?? null),
            taxMinor: $tax,
            totalMinor: $total,
            reason: self::string($raw['reason'] ?? null),
        );
    }

    public static function recurring(mixed $raw): ?RecurringPrice
    {
        if (! is_array($raw)) {
            return null;
        }

        $net = self::int($raw['net_minor'] ?? null);
        $currency = self::string($raw['currency'] ?? null);

        if ($net === null || $currency === null) {
            return null;
        }

        return new RecurringPrice($net, $currency, self::priceTax($raw['tax'] ?? null));
    }

    public static function int(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => null,
        };
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
