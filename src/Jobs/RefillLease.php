<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Jobs;

use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Support\BackgroundJobs;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Tops up one (organization, meter) lease that ran low, so the request that noticed kept serving
 * from the local slice instead of waiting on Billing ({@see LeaseManager::refillIfLow()}). Unique
 * per lease while queued. One try: a failure is reported and its claim stays, which is the
 * backoff before the next low request queues another.
 */
class RefillLease implements RunsOnBillingQueue, ShouldBeUnique, ShouldQueue
{
    use RunsInBackground;

    /** Seconds a queued (or failed) refill blocks another one for the same lease. */
    public const int UNIQUE_FOR = 30;

    public int $tries = 1;

    public int $uniqueFor = self::UNIQUE_FOR;

    public function __construct(
        public readonly string $org,
        public readonly string $meter,
    ) {}

    public static function claim(string $org, string $meter): string
    {
        return 'refill:'.hash('sha256', $org."\0".$meter);
    }

    public function uniqueId(): string
    {
        return self::claim($this->org, $this->meter);
    }

    public function handle(LeaseManager $leases, BackgroundJobs $jobs): void
    {
        try {
            $leases->refillInBackground($this->org, $this->meter);
        } catch (Throwable $e) {
            $jobs->report($e);

            return;
        }

        $jobs->release(self::claim($this->org, $this->meter));
    }
}
