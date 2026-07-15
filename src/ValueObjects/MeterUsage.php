<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One meter's usage within the current period (`GET /api/v1/usage/{org}`): units
 * `used`, the included `allowance`, and the `overage` beyond it. `overage` is what the
 * app surfaces to warn a user they are into paid (or blocked) territory.
 */
readonly class MeterUsage
{
    public function __construct(
        public int $used,
        public int $allowance,
        public int $overage,
    ) {}

    /** Remaining included allowance (never negative — past the allowance it is zero). */
    public function remaining(): int
    {
        return max(0, $this->allowance - $this->used);
    }
}
