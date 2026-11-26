<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use InvalidArgumentException;

/** Always inspect allocation state too: a committed claim can refer to released bytes. */
readonly class CapacityClaim
{
    public function __construct(
        public string $agreement,
        public string $key,
        public string $state,
        public CapacityAllocation $allocation,
    ) {
        if ($agreement === '' || $key === '' || $allocation->agreement !== $agreement
            || ! in_array($state, ['reserved', 'committed', 'canceled'], true)
            || ($state === 'committed' && ! in_array($allocation->state, ['committed', 'released'], true))
            || ($state === 'reserved' && ! in_array($allocation->state, ['reserved', 'committed'], true))) {
            throw new InvalidArgumentException('Invalid capacity claim.');
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['object'] ?? null) !== 'capacity_claim' || ! is_string($data['agreement'] ?? null)
            || ! is_string($data['key'] ?? null) || ! is_string($data['state'] ?? null)
            || ! is_array($data['allocation'] ?? null)) {
            throw new InvalidArgumentException('Malformed capacity claim.');
        }

        return new self($data['agreement'], $data['key'], $data['state'], CapacityAllocation::fromArray($data['allocation']));
    }
}
