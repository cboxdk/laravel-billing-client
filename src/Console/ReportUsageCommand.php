<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Console;

use Cbox\Billing\Client\Reporting\UsageReporter;
use Illuminate\Console\Command;

/**
 * Flushes the durable usage ledger to billing — the scheduled, off-the-hot-path half
 * of metering. Schedule it at the configured `report_interval` (e.g. every minute)
 * so cumulative totals reach billing promptly; because reporting is cumulative and
 * self-correcting, a missed run simply backfills on the next.
 */
class ReportUsageCommand extends Command
{
    protected $signature = 'billing:report-usage {--org= : Restrict the flush to a single organization}';

    protected $description = 'Flush buffered cumulative usage to the billing service.';

    public function handle(UsageReporter $reporter): int
    {
        $org = $this->option('org');
        $reported = $reporter->flush(is_string($org) && $org !== '' ? $org : null);

        $this->info("Reported cumulative usage for {$reported} organization(s).");

        return self::SUCCESS;
    }
}
