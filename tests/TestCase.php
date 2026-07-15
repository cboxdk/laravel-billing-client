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
}
