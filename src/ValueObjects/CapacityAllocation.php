<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use InvalidArgumentException;

/** A physical generation, not a reusable content hash. A released generation stays closed. */
readonly class CapacityAllocation
{
    public const MAX_AMOUNT = 9007199254740991;

    public function __construct(
        public string $agreement,
        public string $key,
        public string $org,
        public int $estimate,
        public ?int $actual,
        public string $state,
        public ?string $deletion,
    ) {
        if ($agreement === '' || $key === '' || $org === '' || $estimate < 1 || $estimate > self::MAX_AMOUNT
            || ! in_array($state, ['reserved', 'committed', 'canceled', 'released'], true)
            || ($actual !== null && ($actual < 0 || $actual > $estimate))
            || (in_array($state, ['committed', 'released'], true) !== ($actual !== null))
            || (($state === 'released') !== ($deletion !== null)) || $deletion === '') {
            throw new InvalidArgumentException('Invalid capacity allocation.');
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['object'] ?? null) !== 'capacity_allocation'
            || ! is_string($data['agreement'] ?? null) || ! is_string($data['key'] ?? null)
            || ! is_string($data['org'] ?? null) || ! is_int($data['estimate'] ?? null)
            || ! is_string($data['state'] ?? null) || ! array_key_exists('actual', $data)
            || ! array_key_exists('deletion', $data)
            || ($data['actual'] !== null && ! is_int($data['actual']))
            || ($data['deletion'] !== null && ! is_string($data['deletion']))) {
            throw new InvalidArgumentException('Malformed capacity allocation.');
        }

        return new self($data['agreement'], $data['key'], $data['org'], $data['estimate'], $data['actual'], $data['state'], $data['deletion']);
    }
}
