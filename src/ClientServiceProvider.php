<?php

declare(strict_types=1);

namespace Cbox\Billing\Client;

use Cbox\Billing\Client\Buffers\CacheUsageBuffer;
use Cbox\Billing\Client\Buffers\DatabaseUsageBuffer;
use Cbox\Billing\Client\Console\ReportUsageCommand;
use Cbox\Billing\Client\Console\SweepReservationsCommand;
use Cbox\Billing\Client\Contracts\BillingSignals;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\ReservationRegistry;
use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Leasing\ReservationSweeper;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\Signals\NullBillingSignals;
use Cbox\Billing\Client\Stores\CacheLeaseStore;
use Cbox\Billing\Client\Stores\CacheReservationRegistry;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the app-local billing client. The node-local lease store, reservation
 * registry, and durable usage buffer are always bound (cache-backed by default, the
 * buffer optionally database-backed); the HTTP transports are bound only when a base
 * URL and API token are configured — without them the host must bind its own
 * {@see BillingTransport}/{@see ManagementTransport} (e.g. the in-memory fakes in
 * tests), keeping the package deny-by-default about the network rather than pointing
 * at a phantom endpoint.
 */
class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing-client.php', 'billing-client');

        $this->registerSignals();
        $this->registerStores();
        $this->registerTransport();
        $this->registerServices();
        $this->registerManagement();
    }

    private function registerSignals(): void
    {
        $this->app->singleton(BillingSignals::class, static fn (): NullBillingSignals => new NullBillingSignals);
    }

    private function registerStores(): void
    {
        $this->app->singleton(LocalLeaseStore::class, static function (Application $app): CacheLeaseStore {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            return new CacheLeaseStore(self::cache($app, $config), $prefix.':lease:');
        });

        $this->app->singleton(ReservationRegistry::class, static function (Application $app): CacheReservationRegistry {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            return new CacheReservationRegistry(self::cache($app, $config), $prefix.':pending:');
        });

        $this->app->singleton(UsageBuffer::class, static function (Application $app): UsageBuffer {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            if (self::stringConfig($config, 'billing-client.buffer', 'cache') === 'database') {
                return new DatabaseUsageBuffer(
                    $app->make(ConnectionResolverInterface::class),
                    self::nullableStringConfig($config, 'billing-client.buffer_connection'),
                    self::stringConfig($config, 'billing-client.buffer_table', 'billing_client_usage'),
                );
            }

            return new CacheUsageBuffer(self::cache($app, $config), $prefix.':usage:');
        });
    }

    private function registerTransport(): void
    {
        $this->app->singleton(BillingTransport::class, static function (Application $app): HttpBillingTransport {
            $config = $app->make(Config::class);

            return new HttpBillingTransport(
                $app->make(HttpFactory::class),
                self::stringConfig($config, 'billing-client.base_url', ''),
                self::stringConfig($config, 'billing-client.api_token', ''),
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
                self::lockProvider($app, $config),
                self::intConfig($config, 'billing-client.refill_lock_ttl', 10),
                self::intConfig($config, 'billing-client.refill_lock_wait', 5),
                $app->make(BillingSignals::class),
            );
        });

        $this->app->singleton(UsageReporter::class, static fn (Application $app): UsageReporter => new UsageReporter(
            $app->make(BillingTransport::class),
            $app->make(UsageBuffer::class),
            $app->make(BillingSignals::class),
        ));

        $this->app->singleton(ReservationSweeper::class, static fn (Application $app): ReservationSweeper => new ReservationSweeper(
            $app->make(LocalLeaseStore::class),
            $app->make(ReservationRegistry::class),
            $app->make(BillingSignals::class),
        ));

        $this->app->singleton(BillingClient::class, static function (Application $app): BillingClient {
            $config = $app->make(Config::class);

            return new BillingClient(
                store: $app->make(LocalLeaseStore::class),
                buffer: $app->make(UsageBuffer::class),
                leases: $app->make(LeaseManager::class),
                reporter: $app->make(UsageReporter::class),
                transport: $app->make(BillingTransport::class),
                failurePolicy: FailurePolicy::fromConfig($config->get('billing-client.fail')),
                registry: $app->make(ReservationRegistry::class),
                signals: $app->make(BillingSignals::class),
                reservationTtl: self::intConfig($config, 'billing-client.reservation_ttl', 300),
            );
        });

        $this->app->alias(BillingClient::class, 'billing-client');
    }

    private function registerManagement(): void
    {
        $this->app->singleton(ManagementTransport::class, static function (Application $app): HttpManagementTransport {
            $config = $app->make(Config::class);

            return new HttpManagementTransport(
                $app->make(HttpFactory::class),
                self::stringConfig($config, 'billing-client.base_url', ''),
                self::stringConfig($config, 'billing-client.api_token', ''),
                self::intConfig($config, 'billing-client.timeout', 5),
            );
        });

        $this->app->singleton(BillingManagement::class, static fn (Application $app): BillingManagement => new BillingManagement(
            $app->make(ManagementTransport::class),
        ));

        $this->app->alias(BillingManagement::class, 'billing-management');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing-client.php' => $this->app->configPath('billing-client.php'),
            ], 'billing-client-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'billing-client-migrations');

            $this->commands([ReportUsageCommand::class, SweepReservationsCommand::class]);
        }

        // Auto-load the ledger migration only when the database buffer is in use, so a
        // cache-buffer host is never handed a table it does not need.
        $config = $this->app->make(Config::class);

        if (self::stringConfig($config, 'billing-client.buffer', 'cache') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    private static function cache(Application $app, Config $config): CacheRepository
    {
        return $app->make(CacheFactory::class)->store(self::cacheStore($config));
    }

    /**
     * The cache store's lock provider for single-flight refills, or null when the
     * configured store cannot mint atomic locks (the refill then runs directly).
     */
    private static function lockProvider(Application $app, Config $config): ?LockProvider
    {
        $store = self::cache($app, $config)->getStore();

        return $store instanceof LockProvider ? $store : null;
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

    private static function nullableStringConfig(Config $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intConfig(Config $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }
}
