<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Buffers;

use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Illuminate\Contracts\Cache\Repository;

/**
 * {@see UsageBuffer} backed by Laravel's cache — the default durable ledger. Each
 * {@see record()} is an atomic `increment` of the per-(org, meter) cumulative counter
 * (the append-before-count write) plus an atomic bump of its seq; a small index key
 * tracks which (org, meter) pairs exist so the reporter can enumerate them without a
 * key scan.
 *
 * Point this at a persistent cache store (Redis/database) in production so the
 * cumulative totals survive a crash between append and report. Because reporting is
 * cumulative, nothing is ever removed here on a successful flush — the counter is the
 * running total, and a lost report is backfilled by the next snapshot.
 */
class CacheUsageBuffer implements UsageBuffer
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'cbox-billing-client:usage:',
    ) {}

    public function record(string $org, string $meter, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        $this->remember($org, $meter);
        $this->cache->increment($this->cumulativeKey($org, $meter), $units);
        $this->cache->increment($this->seqKey($org, $meter), 1);
    }

    public function cumulative(string $org, string $meter): int
    {
        $value = $this->cache->get($this->cumulativeKey($org, $meter), 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function snapshot(?string $org = null): array
    {
        $out = [];

        foreach ($this->index() as $pair) {
            [$pairOrg, $meter] = $pair;

            if ($org !== null && $pairOrg !== $org) {
                continue;
            }

            $out[] = new CumulativeUsage(
                $pairOrg,
                $meter,
                $this->cumulative($pairOrg, $meter),
                $this->seq($pairOrg, $meter),
            );
        }

        return $out;
    }

    private function seq(string $org, string $meter): int
    {
        $value = $this->cache->get($this->seqKey($org, $meter), 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The set of (org, meter) pairs the buffer has seen. Stored as a newline-joined
     * string of "org\tmeter" rows so it round-trips on any cache driver.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function index(): array
    {
        $raw = $this->cache->get($this->indexKey());

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $pairs = [];

        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", $line, 2);

            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $pairs[] = [$parts[0], $parts[1]];
            }
        }

        return $pairs;
    }

    private function remember(string $org, string $meter): void
    {
        foreach ($this->index() as $pair) {
            if ($pair[0] === $org && $pair[1] === $meter) {
                return;
            }
        }

        $raw = $this->cache->get($this->indexKey());
        $prefix = is_string($raw) && $raw !== '' ? $raw."\n" : '';

        $this->cache->forever($this->indexKey(), $prefix.$org."\t".$meter);
    }

    private function cumulativeKey(string $org, string $meter): string
    {
        return $this->prefix.'cum:'.$org.':'.$meter;
    }

    private function seqKey(string $org, string $meter): string
    {
        return $this->prefix.'seq:'.$org.':'.$meter;
    }

    private function indexKey(): string
    {
        return $this->prefix.'index';
    }
}
