<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use InvalidArgumentException;

/**
 * Public ids for the fakes, shaped like the ones Billing hands out: `{prefix}_` plus 22 base62
 * characters (`inv_0000000000000000000001`). Deterministic, so a test can predict the id the
 * n-th object gets with `FakePublicId::make('inv', n)`.
 */
class FakePublicId
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function make(string $prefix, int $sequence): string
    {
        if (preg_match('/^[a-z]+$/D', $prefix) !== 1 || $sequence < 0) {
            throw new InvalidArgumentException('A public id needs a lower-case prefix and a nonnegative sequence.');
        }

        $digits = '';
        do {
            $digits = self::ALPHABET[$sequence % 62].$digits;
            $sequence = intdiv($sequence, 62);
        } while ($sequence > 0);

        return $prefix.'_'.str_pad($digits, 22, '0', STR_PAD_LEFT);
    }
}
