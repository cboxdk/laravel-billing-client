<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Jobs;

use Cbox\Billing\Client\Support\BackgroundJobs;

/**
 * A job {@see BackgroundJobs} routes to the configured `billing-client.queue.connection` and
 * `billing-client.queue.name` ({@see RunsInBackground} implements it).
 */
interface RunsOnBillingQueue
{
    /** Null keeps the app's default connection or queue. */
    public function routeTo(?string $connection, ?string $queue): void;
}
