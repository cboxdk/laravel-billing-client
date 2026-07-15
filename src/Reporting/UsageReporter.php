<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Reporting;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;

/**
 * Ships the durable usage ledger to billing in the background — the tier-2, off-the-
 * hot-path half of metering. It reads the CUMULATIVE snapshot from the
 * {@see UsageBuffer} and POSTs each org's running totals to billing's ingest.
 *
 * Reporting is cumulative and therefore self-correcting: it sends the running total,
 * not a delta, so nothing is drained on success and a report that never lands is
 * backfilled on the next flush (the total already includes the missed usage). A
 * transport fault leaves the ledger untouched to be retried, so no usage is ever lost
 * to a failed report.
 */
class UsageReporter
{
    public function __construct(
        private readonly BillingTransport $transport,
        private readonly UsageBuffer $buffer,
    ) {}

    /**
     * Flush the buffered cumulative totals for every org (or one org) to billing.
     * Returns the number of orgs successfully reported. A per-org transport fault is
     * collected and swallowed so one unreachable org does not block the others; the
     * failed org's totals stay in the ledger for the next flush.
     */
    public function flush(?string $org = null): int
    {
        $reported = 0;

        foreach ($this->groupByOrg($this->buffer->snapshot($org)) as $orgId => $entries) {
            try {
                $this->transport->reportUsage($orgId, $entries);
                $reported++;
            } catch (TransportException) {
                // Cumulative totals remain in the buffer; the next flush backfills.
            }
        }

        return $reported;
    }

    /**
     * @param  list<CumulativeUsage>  $snapshot
     * @return array<string, list<CumulativeUsage>>
     */
    private function groupByOrg(array $snapshot): array
    {
        $grouped = [];

        foreach ($snapshot as $entry) {
            $grouped[$entry->org][] = $entry;
        }

        return $grouped;
    }
}
