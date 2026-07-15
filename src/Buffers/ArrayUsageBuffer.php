<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Buffers;

use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;

/**
 * In-memory {@see UsageBuffer} for a single process and for tests. It keeps the
 * cumulative total and a monotonic seq per (org, meter). A production SDK backs the
 * buffer with a durable local queue/WAL so a crash after append still replays; the
 * interface — and the cumulative, self-correcting reporting contract — is identical.
 */
class ArrayUsageBuffer implements UsageBuffer
{
    /** @var array<string, array{org: string, meter: string, cumulative: int, seq: int}> */
    private array $totals = [];

    public function record(string $org, string $meter, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        $key = $org.':'.$meter;
        $current = $this->totals[$key] ?? ['org' => $org, 'meter' => $meter, 'cumulative' => 0, 'seq' => 0];

        $this->totals[$key] = [
            'org' => $org,
            'meter' => $meter,
            'cumulative' => $current['cumulative'] + $units,
            'seq' => $current['seq'] + 1,
        ];
    }

    public function cumulative(string $org, string $meter): int
    {
        return $this->totals[$org.':'.$meter]['cumulative'] ?? 0;
    }

    public function snapshot(?string $org = null): array
    {
        $out = [];

        foreach ($this->totals as $row) {
            if ($org !== null && $row['org'] !== $org) {
                continue;
            }

            $out[] = new CumulativeUsage($row['org'], $row['meter'], $row['cumulative'], $row['seq']);
        }

        return $out;
    }
}
