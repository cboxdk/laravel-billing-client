<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

readonly class UsageEventRejection
{
    public function __construct(public int $index, public string $id, public string $reason) {}

    /** @return array{index: int, id: string, reason: string} */
    public function toArray(): array
    {
        return ['index' => $this->index, 'id' => $this->id, 'reason' => $this->reason];
    }
}
