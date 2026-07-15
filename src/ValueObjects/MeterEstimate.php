<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * An estimated number of `units` to reserve for one `meter` in an authoritative
 * remote reservation (`POST /api/v1/reserve`).
 */
readonly class MeterEstimate
{
    public function __construct(
        public string $meter,
        public int $estimate,
    ) {}

    /**
     * @return array{meter: string, estimate: int}
     */
    public function toArray(): array
    {
        return ['meter' => $this->meter, 'estimate' => $this->estimate];
    }
}
