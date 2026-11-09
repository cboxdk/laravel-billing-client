<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Support;

use Cbox\Billing\Client\Jobs\RunsOnBillingQueue;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Queues a background job at most once per claim: the first caller claims the key in the cache
 * store (an atomic add) and dispatches; everyone else, until the job releases the claim or it
 * runs out, does nothing. That is what keeps a burst of pages from queueing a burst of identical
 * refreshes, and, because a job that fails leaves its claim in place, it is also the backoff:
 * after a failure nothing is queued again for the claim's lifetime.
 *
 * Dispatching never throws into the request: a queue that is down is reported and the claim is
 * dropped, so the next request tries again. Run a real queue in production; on the `sync`
 * connection the "background" job runs inside the request that queued it.
 */
class BackgroundJobs
{
    public function __construct(
        private readonly Container $app,
        private readonly Cache $cache,
        private readonly string $prefix = 'cbox-billing-client:claim:',
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {}

    /** Queue `$job` unless a job with the same claim is already queued or backing off. */
    public function dispatchOnce(string $claim, object $job, int $seconds): bool
    {
        if (! $this->cache->add($this->prefix.$claim, 1, max(1, $seconds))) {
            return false;
        }

        try {
            if ($job instanceof RunsOnBillingQueue) {
                $job->routeTo($this->connection, $this->queue);
            }

            $this->app->make(Dispatcher::class)->dispatch($job);

            return true;
        } catch (Throwable $e) {
            $this->release($claim);
            $this->report($e);

            return false;
        }
    }

    /** Let the next caller queue the job again (the job finished what it came for). */
    public function release(string $claim): void
    {
        $this->cache->forget($this->prefix.$claim);
    }

    public function report(Throwable $e): void
    {
        if ($this->app->bound(ExceptionHandler::class)) {
            $this->app->make(ExceptionHandler::class)->report($e);
        }
    }
}
