<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Tells kept answers what kind of work is asking: a safe HTTP request (GET, HEAD, OPTIONS), a
 * change (POST, PUT, PATCH, DELETE), or no HTTP request at all (a queued job, a command). Bound
 * as a singleton; a host on a long-running server whose `runningInConsole()` is true while it
 * serves HTTP can bind its own subclass.
 */
class CurrentRequest
{
    public function __construct(private readonly Application $app) {}

    /** True for a safe HTTP method, false for a change, null when no HTTP request is served. */
    public function safe(): ?bool
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return null;
        }

        if (! $this->app->bound('request')) {
            return null;
        }

        return $this->app->make(Request::class)->isMethodSafe();
    }

    /**
     * The HTTP request being served, or null when none is. Kept answers remember what they read
     * for as long as this object lives, so one request reads the store once per question.
     */
    public function instance(): ?object
    {
        if ($this->safe() === null || ! $this->app->bound('request')) {
            return null;
        }

        return $this->app->make(Request::class);
    }
}
