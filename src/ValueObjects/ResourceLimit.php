<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/** An observed resource ceiling, not a capacity reservation, price or credit allowance. */
readonly class ResourceLimit
{
    public function __construct(
        public string $key = '',
        public bool $enabled = false,
        public int $limit = 0,
        public int $baseLimit = 0,
        public ?int $grantRequestId = null,
        public ?DateTimeImmutable $expiresAt = null,
        public string $source = 'catalog',
        public ?DateTimeImmutable $observedAt = null,
    ) {}

    /** Unknown/stale/expired means re-read Billing, never keep an expired increase. */
    public function ceilingAt(DateTimeImmutable $now, int $maximumAgeSeconds): ?int
    {
        if ($maximumAgeSeconds < 0) {
            throw new InvalidArgumentException('The maximum observation age cannot be negative.');
        }
        if ($this->observedAt === null || $this->observedAt > $now
            || $now->getTimestamp() - $this->observedAt->getTimestamp() > $maximumAgeSeconds
            || ($this->expiresAt !== null && $this->expiresAt <= $now)) {
            return null;
        }

        return $this->enabled ? $this->limit : 0;
    }
}
