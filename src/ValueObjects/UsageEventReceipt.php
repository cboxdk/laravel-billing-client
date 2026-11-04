<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Exceptions\TransportException;

/** Accepted and duplicate counts are aggregate: the server does not distinguish them per ID. */
readonly class UsageEventReceipt
{
    /** @param list<UsageEventRejection> $rejected */
    private function __construct(public int $accepted, public int $duplicates, public array $rejected) {}

    /**
     * A malformed acknowledgement leaves the entire batch unresolved, including apparent successes.
     *
     * @param  array<string, mixed>  $body
     * @param  list<UsageEvent>  $events
     */
    public static function fromResponse(array $body, array $events): self
    {
        $bad = static fn (): TransportException => TransportException::malformed('/api/v1/events', 'inconsistent usage event receipt');
        $accepted = $body['accepted'] ?? null;
        $duplicates = $body['duplicates'] ?? null;
        $rows = $body['rejected'] ?? null;
        if (($body['ok'] ?? null) !== true || ! is_int($accepted) || ! is_int($duplicates)
            || $accepted < 0 || $duplicates < 0 || $accepted > count($events) || $duplicates > count($events)
            || ! is_array($rows) || ! array_is_list($rows)
            || $accepted + $duplicates + count($rows) !== count($events)) {
            throw $bad();
        }
        $seen = [];
        $rejected = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['index'] ?? null) || ! isset($events[$row['index']])
                || isset($seen[$row['index']]) || ($row['id'] ?? null) !== $events[$row['index']]->id
                || ! is_string($row['reason'] ?? null) || $row['reason'] === '') {
                throw $bad();
            }
            $seen[$row['index']] = true;
            $rejected[] = new UsageEventRejection($row['index'], $row['id'], $row['reason']);
        }

        return new self($accepted, $duplicates, $rejected);
    }

    /** @return array{ok: true, accepted: int, duplicates: int, rejected: list<array{index: int, id: string, reason: string}>} */
    public function toArray(): array
    {
        return ['ok' => true, 'accepted' => $this->accepted, 'duplicates' => $this->duplicates,
            'rejected' => array_map(static fn (UsageEventRejection $row): array => $row->toArray(), $this->rejected)];
    }
}
