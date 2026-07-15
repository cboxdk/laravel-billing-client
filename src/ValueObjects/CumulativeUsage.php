<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One meter's running usage total for an organization, as reported to billing
 * (`POST /api/v1/usage`). Reporting is CUMULATIVE and self-correcting: `cumulative`
 * is the monotonically-increasing total consumed on this node since the period
 * began, and `seq` is a per-(org, meter) monotonic version. Billing keeps the
 * highest cumulative it has seen per (node, meter), so a dropped report is backfilled
 * by the next one — the running total already includes the lost delta.
 */
readonly class CumulativeUsage
{
    public function __construct(
        public string $org,
        public string $meter,
        public int $cumulative,
        public int $seq,
    ) {}

    /**
     * The wire shape billing ingests, minus `org` (which is sent once per request).
     *
     * @return array{meter: string, cumulative: int, seq: int}
     */
    public function toEntry(): array
    {
        return [
            'meter' => $this->meter,
            'cumulative' => $this->cumulative,
            'seq' => $this->seq,
        ];
    }
}
