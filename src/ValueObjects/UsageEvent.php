<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/** Persist this payload before sending; retries must retain both ID and occurrence time. */
readonly class UsageEvent
{
    /** @param array<string, string> $dimensions */
    public function __construct(
        public string $id,
        public string $meter,
        public int $value,
        public DateTimeImmutable $occurredAt,
        public string $service,
        public array $dimensions = [],
        public ?string $uniqueKey = null,
        public int $weight = 1,
    ) {
        foreach ([[$id, 128], [$meter, 255], [$service, 255]] as [$text, $max]) {
            if ((string) $text === '' || mb_strlen((string) $text) > $max) {
                throw new InvalidArgumentException('Usage event identifiers must be nonempty and within server limits.');
            }
        }
        if ($value < 0 || $value > 1_000_000_000_000_000 || $weight < 0 || $weight > 1_000_000_000_000_000) {
            throw new InvalidArgumentException('Usage quantities and weights must be between 0 and 1000000000000000.');
        }
        if ($uniqueKey !== null && ($uniqueKey === '' || mb_strlen($uniqueKey) > 255)) {
            throw new InvalidArgumentException('A usage series key must have 1 to 255 characters.');
        }
        if (count($dimensions) > 10) {
            throw new InvalidArgumentException('A usage event supports at most 10 dimensions.');
        }
        foreach ($dimensions as $key => $text) {
            if (! self::validDimension($key, $text)) {
                throw new InvalidArgumentException('Usage dimensions require lowercase keys and string values of at most 128 characters.');
            }
        }
    }

    /** @return array{id: string, meter: string, value: int, occurred_at: string, service: string, dimensions: array<string, string>, unique_key?: string, weight: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'meter' => $this->meter, 'value' => $this->value,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'service' => $this->service, 'dimensions' => $this->dimensions,
            ...($this->uniqueKey === null ? [] : ['unique_key' => $this->uniqueKey]),
            'weight' => $this->weight,
        ];
    }

    private static function validDimension(mixed $key, mixed $text): bool
    {
        return is_string($key) && preg_match('/^[a-z0-9_]{1,64}$/D', $key) === 1
            && is_string($text) && mb_strlen($text) <= 128;
    }

    /**
     * Validate untrusted array shape before serializing a batch.
     *
     * @param  array<array-key, mixed>  $events
     */
    public static function validateBatch(string $org, array $events): void
    {
        if ($org === '' || ! array_is_list($events) || count($events) < 1 || count($events) > 1000) {
            throw new InvalidArgumentException('An organization and a list of 1 to 1000 usage events are required.');
        }
        $ids = [];
        foreach ($events as $event) {
            if (! $event instanceof self || isset($ids[$event->id])) {
                throw new InvalidArgumentException('A batch requires usage events with distinct IDs.');
            }
            $ids[$event->id] = true;
        }
    }
}
