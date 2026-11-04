<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/** A server-calculated usage quantity for one combination of meter dimensions. */
readonly class UsageGroup
{
    /** @param array<string, string|null> $dimensions Null means the event lacked this dimension. */
    public function __construct(public array $dimensions, public int $used) {}

    /** @return array{dimensions: array<string, string|null>, used: int} */
    public function toArray(): array
    {
        return ['dimensions' => $this->dimensions, 'used' => $this->used];
    }
}
