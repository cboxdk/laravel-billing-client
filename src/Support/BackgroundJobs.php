<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Support;

use Cbox\Billing\Client\Jobs\RunsOnBillingQueue;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Queues a background job at most once per claim: the first caller claims the key in the cache
 * store (an atomic add) and dispatches; everyone else, until the job releases the claim or it
 * runs out, does nothing. That is what keeps a burst of pages from queueing a burst of identical
 * refreshes, and, because a job that fails leaves its claim in place, it is also the backoff:
 * after a failure nothing is queued again for the claim's lifetime.
 *
 * Dispatching never throws into the request: a queue that is down is reported and the claim is
 * dropped, so the next request tries again. Run a real queue in production. An app with no
 * queue (the `sync` connection) still does not wait: while an HTTP request is served the job
 * runs after the response has been sent. Only a command on the `sync` connection runs it inline.
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

            if ($this->afterResponse()) {
                $this->dispatchAfterResponse($claim, $job);
            } else {
                $this->app->make(Dispatcher::class)->dispatch($job);
            }

            return true;
        } catch (Throwable $e) {
            $this->release($claim);
            $this->report($e);

            return false;
        }
    }

    /**
     * True when the job would otherwise run inside the HTTP request that queued it: the queue
     * connection is `sync` (the app runs no queue) and a request is being served.
     */
    private function afterResponse(): bool
    {
        if (! $this->app instanceof Application || ($this->app->runningInConsole() && ! $this->app->runningUnitTests())) {
            return false;
        }

        $config = $this->app->make(Config::class);
        $connection = $this->connection ?? $config->get('queue.default');

        return is_string($connection) && $config->get('queue.connections.'.$connection.'.driver') === 'sync';
    }

    /** Run `$job` once the response has been sent; a failure is reported and frees the claim. */
    private function dispatchAfterResponse(string $claim, object $job): void
    {
        if (! $this->app instanceof Application) {
            return;
        }

        $ran = false;
        $this->app->terminating(function () use ($claim, $job, &$ran): void {
            if ($ran) {
                return;
            }
            $ran = true;

            try {
                $this->app->make(Dispatcher::class)->dispatchSync($job);
            } catch (Throwable $e) {
                $this->release($claim);
                $this->report($e);
            }
        });
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
