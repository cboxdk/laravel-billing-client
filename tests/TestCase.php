<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Tests;

use Cbox\Billing\Client\ClientServiceProvider;
use Cbox\Billing\Client\Testing\InteractsWithBillingClient;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithBillingClient;

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [ClientServiceProvider::class];
    }

    /**
     * The suite runs as an app with a real queue does: a queued job is handed to the queue and
     * does not run in the test (the `null` driver drops it; `Bus::fake()` records it). A test
     * about an app without a queue sets `queue.default` to `sync` itself.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.connections.background', ['driver' => 'null']);
        $app['config']->set('queue.default', 'background');
    }
}
