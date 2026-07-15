<?php

declare(strict_types=1);

namespace Cbox\Billing\Client;

use Cbox\Billing\Client\Buffers\CacheUsageBuffer;
use Cbox\Billing\Client\Console\ReportUsageCommand;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\Stores\CacheLeaseStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the app-local billing client. The node-local lease store and durable usage
 * buffer are always bound (cache-backed); the HTTP transport is bound only when a
 * base URL and API token are configured — without them the host must bind its own
 * {@see BillingTransport} (e.g. the in-memory fake in tests), keeping the package
 * deny-by-default about the network rather than pointing at a phantom endpoint.
 */
class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing-client.php', 'billing-client');

        $this->registerStores();
        $this->registerTransport();
        $this->registerServices();
    }

    private function registerStores(): void
    {
        $this->app->singleton(LocalLeaseStore::class, static function (Application $app): CacheLeaseStore {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            return new CacheLeaseStore(
                $app->make(CacheFactory::class)->store(self::cacheStore($config)),
                $prefix.':lease:',
            );
        });

        $this->app->singleton(UsageBuffer::class, static function (Application $app): CacheUsageBuffer {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            return new CacheUsageBuffer(
                $app->make(CacheFactory::class)->store(self::cacheStore($config)),
                $prefix.':usage:',
            );
        });
    }

    private function registerTransport(): void
    {
        $this->app->singleton(BillingTransport::class, static function (Application $app): HttpBillingTransport {
            $config = $app->make(Config::class);

            $baseUrl = self::stringConfig($config, 'billing-client.base_url', '');
            $token = self::stringConfig($config, 'billing-client.api_token', '');

            return new HttpBillingTransport(
                $app->make(HttpFactory::class),
                $baseUrl,
                $token,
                self::intConfig($config, 'billing-client.timeout', 5),
            );
        });
    }

    private function registerServices(): void
    {
        $this->app->singleton(LeaseManager::class, static function (Application $app): LeaseManager {
            $config = $app->make(Config::class);

            return new LeaseManager(
                $app->make(BillingTransport::class),
                $app->make(LocalLeaseStore::class),
                self::intConfig($config, 'billing-client.lease_size', 100),
                self::intConfig($config, 'billing-client.refill_threshold', 20),
            );
        });

        $this->app->singleton(UsageReporter::class, static fn (Application $app): UsageReporter => new UsageReporter(
            $app->make(BillingTransport::class),
            $app->make(UsageBuffer::class),
        ));

        $this->app->singleton(BillingClient::class, static function (Application $app): BillingClient {
            $config = $app->make(Config::class);

            return new BillingClient(
                $app->make(LocalLeaseStore::class),
                $app->make(UsageBuffer::class),
                $app->make(LeaseManager::class),
                $app->make(UsageReporter::class),
                $app->make(BillingTransport::class),
                FailurePolicy::fromConfig($config->get('billing-client.fail')),
            );
        });

        $this->app->alias(BillingClient::class, 'billing-client');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing-client.php' => $this->app->configPath('billing-client.php'),
            ], 'billing-client-config');

            $this->commands([ReportUsageCommand::class]);
        }
    }

    private static function cacheStore(Config $config): ?string
    {
        $store = $config->get('billing-client.cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private static function stringConfig(Config $config, string $key, string $default): string
    {
        $value = $config->get($key);

        return is_string($value) ? $value : $default;
    }

    private static function intConfig(Config $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }
}
