<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Buffers;

use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * Durable, crash-safe {@see UsageBuffer} backed by a relational table. Unlike the
 * cache buffer — whose totals a volatile store (an in-memory or LRU-evicting cache)
 * can silently drop, losing unreported usage — this buffer persists every append to
 * disk, so a crash, restart, or cache eviction between append and report never loses a
 * unit: the running total is durable and the next flush ships it.
 *
 * Each {@see record()} is an idempotent-shaped upsert-then-increment: a missing
 * (org, meter) row is inserted at zero (ignoring a concurrent insert via the unique
 * key), then the cumulative and seq counters are advanced with an atomic SQL
 * `column + n` update — never a read-modify-write — so concurrent workers on the same
 * row cannot lose an increment. Reporting stays cumulative and self-correcting, so
 * nothing is ever deleted here on a successful flush.
 */
class DatabaseUsageBuffer implements UsageBuffer
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly ?string $connection = null,
        private readonly string $table = 'billing_client_usage',
    ) {}

    public function record(string $org, string $meter, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        $connection = $this->connection();

        // Seed a cold row without clobbering a concurrent insert (unique org+meter).
        $this->table($connection)->insertOrIgnore([
            'org' => $org,
            'meter' => $meter,
            'cumulative' => 0,
            'seq' => 0,
            'updated_at' => $this->now(),
        ]);

        // Atomic column-delta advance — never a read-modify-write, so parallel
        // appends on the same row cannot lose an increment.
        $this->table($connection)
            ->where('org', $org)
            ->where('meter', $meter)
            ->incrementEach(
                ['cumulative' => $units, 'seq' => 1],
                ['updated_at' => $this->now()],
            );
    }

    public function cumulative(string $org, string $meter): int
    {
        $value = $this->table($this->connection())
            ->where('org', $org)
            ->where('meter', $meter)
            ->value('cumulative');

        return is_numeric($value) ? (int) $value : 0;
    }

    public function snapshot(?string $org = null): array
    {
        $query = $this->table($this->connection())->orderBy('org')->orderBy('meter');

        if ($org !== null) {
            $query->where('org', $org);
        }

        $out = [];

        foreach ($query->get(['org', 'meter', 'cumulative', 'seq']) as $row) {
            if (! is_string($row->org) || ! is_string($row->meter)) {
                continue;
            }

            $out[] = new CumulativeUsage(
                $row->org,
                $row->meter,
                is_numeric($row->cumulative) ? (int) $row->cumulative : 0,
                is_numeric($row->seq) ? (int) $row->seq : 0,
            );
        }

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    private function table(ConnectionInterface $connection): Builder
    {
        return $connection->table($this->table);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
