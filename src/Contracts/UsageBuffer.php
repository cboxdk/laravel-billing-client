<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Buffers\ArrayUsageBuffer;
use Cbox\Billing\Client\Buffers\CacheUsageBuffer;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;

/**
 * The durable local ledger every committed unit is appended to BEFORE it is counted
 * anywhere else — the crash-safety story. It maintains a monotonic CUMULATIVE total
 * per (org, meter): {@see record()} advances the running total, and {@see snapshot()}
 * reads the current totals for the background reporter to ship. Because the reporter
 * sends cumulative totals (not deltas), the buffer never has to be drained on a
 * successful report and a lost report self-corrects — the total already includes the
 * missed delta.
 *
 * The default ({@see CacheUsageBuffer}) is cache-backed
 * with atomic increments; production points it at a durable store so a crash after
 * append still replays. Tests use {@see ArrayUsageBuffer}.
 */
interface UsageBuffer
{
    /**
     * Durably append `units` of usage for (org, meter) and advance the cumulative
     * total. Append-before-count: this write is what survives a crash.
     */
    public function record(string $org, string $meter, int $units): void;

    /** The current cumulative total consumed for (org, meter) on this node. */
    public function cumulative(string $org, string $meter): int;

    /**
     * Every (org, meter) cumulative total known to the buffer, oldest-first, for the
     * background reporter to ship. Optionally scoped to a single org.
     *
     * @return list<CumulativeUsage>
     */
    public function snapshot(?string $org = null): array;
}
