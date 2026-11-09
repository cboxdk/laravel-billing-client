<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Jobs;

/**
 * The queue routing of a {@see RunsOnBillingQueue} job: the public `connection` and `queue`
 * properties Laravel's dispatcher reads. Null means the app's default.
 */
trait RunsInBackground
{
    public ?string $connection = null;

    public ?string $queue = null;

    public function routeTo(?string $connection, ?string $queue): void
    {
        $this->connection = $connection;
        $this->queue = $queue;
    }
}
