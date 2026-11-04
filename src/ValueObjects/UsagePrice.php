<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A plain per-unit or per-block catalog rate. Each started block of `per` units
 * costs `minor` in `currency`; this is not a proportional fractional-unit price.
 * Tax follows the plan's pricing convention, not the recurring price's tax quote.
 * Tiered, dimension-dependent and percentage prices are not represented here.
 */
readonly class UsagePrice
{
    public function __construct(
        public int $minor,
        public string $currency,
        public int $per,
    ) {}

    /** @return array{minor: int, currency: string, per: int} */
    public function toArray(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency, 'per' => $this->per];
    }
}
