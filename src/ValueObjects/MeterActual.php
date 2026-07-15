<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * The actual number of `units` consumed for one `meter`, settling a remote
 * reservation (`POST /api/v1/commit`).
 */
readonly class MeterActual
{
    public function __construct(
        public string $meter,
        public int $actual,
    ) {}

    /**
     * @return array{meter: string, actual: int}
     */
    public function toArray(): array
    {
        return ['meter' => $this->meter, 'actual' => $this->actual];
    }
}
