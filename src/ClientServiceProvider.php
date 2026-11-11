<?php

declare(strict_types=1);

namespace Cbox\Billing\Client;

use Cbox\Billing\Client\Access\ServiceAccess;
use Cbox\Billing\Client\Buffers\CacheUsageBuffer;
use Cbox\Billing\Client\Buffers\DatabaseUsageBuffer;
use Cbox\Billing\Client\Console\ReportUsageCommand;
use Cbox\Billing\Client\Console\SweepReservationsCommand;
use Cbox\Billing\Client\Console\SyncDefinitionsCommand;
use Cbox\Billing\Client\Contracts\AllowanceSnapshots;
use Cbox\Billing\Client\Contracts\BillingSignals;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\DefinitionTransport;
use Cbox\Billing\Client\Contracts\FeatureReader;
use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\ReservationRegistry;
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Contracts\UsageBuffer;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Http\ApiUrl;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\Http\HttpDefinitionTransport;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Http\HttpServiceAccessTransport;
use Cbox\Billing\Client\Jobs\RefillLease;
use Cbox\Billing\Client\Kept\CacheKeptAnswers;
use Cbox\Billing\Client\Kept\CurrentRequest;
use Cbox\Billing\Client\Kept\KeptSubscriptions;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Leasing\OutageGrace;
use Cbox\Billing\Client\Leasing\RefillBreaker;
use Cbox\Billing\Client\Leasing\ReservationSweeper;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\Signals\NullBillingSignals;
use Cbox\Billing\Client\Stores\CacheAllowanceSnapshots;
use Cbox\Billing\Client\Stores\CacheLeaseStore;
use Cbox\Billing\Client\Stores\CacheReservationRegistry;
use Cbox\Billing\Client\Support\BackgroundJobs;
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
 * buffer optionally database-backed). The HTTP transports call the API URL that
 * {@see ApiUrl} resolves from `api_url` (or the old `base_url`); without an API token
 * Billing refuses every call, so a host that has none binds its own
 * {@see BillingTransport}/{@see ManagementTransport} (e.g. the in-memory fakes in tests).
 */
class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing-client.php', 'billing-client');

        $this->app->singleton(DefinitionTransport::class, static function (Application $app): HttpDefinitionTransport {
            $config = $app->make(Config::class);

            return new HttpDefinitionTransport(
                $app->make(HttpFactory::class),
                self::apiUrl($config),
                self::stringConfig($config, 'billing-client.definition_token', ''),
                connectTimeout: self::intConfig($config, 'billing-client.connect_timeout', 2),
            );
        });
        $this->registerSignals();
        $this->registerStores();
        $this->registerTransport();
        $this->registerServices();
        $this->registerManagement();
        $this->registerKeptAnswers();
    }

    /**
     * Kept answers (stale-while-revalidate), the background jobs that refresh them, and the
     * two readers built on them: service access and the organization's subscription.
     */
    private function registerKeptAnswers(): void
    {
        $this->app->singleton(BackgroundJobs::class, static function (Application $app): BackgroundJobs {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            return new BackgroundJobs(
                $app,
                self::cache($app, $config),
                $prefix.':claim:',
                self::nullableStringConfig($config, 'billing-client.queue.connection'),
                self::nullableStringConfig($config, 'billing-client.queue.name'),
            );
        });

        $this->app->singleton(CurrentRequest::class, static fn (Application $app): CurrentRequest => new CurrentRequest($app));

        $this->app->singleton(KeptAnswers::class, static function (Application $app): CacheKeptAnswers {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            return new CacheKeptAnswers(
                $app,
                self::cache($app, $config),
                $app->make(CurrentRequest::class),
                $app->make(BackgroundJobs::class),
                self::intConfig($config, 'billing-client.kept.fresh_for', 30),
                self::intConfig($config, 'billing-client.kept.max_age', 86_400),
                self::intConfig($config, 'billing-client.kept.keep_for', 604_800),
                $prefix.':kept:',
            );
        });

        $this->app->singleton(ServiceAccessTransport::class, static function (Application $app): HttpServiceAccessTransport {
            $config = $app->make(Config::class);

            return new HttpServiceAccessTransport(
                $app->make(HttpFactory::class),
                self::serviceAccessUrl($config),
                self::nullableStringConfig($config, 'billing-client.service_access.token') ?? self::stringConfig($config, 'billing-client.api_token', ''),
                self::intConfig($config, 'billing-client.timeout', 5),
                self::intConfig($config, 'billing-client.connect_timeout', 2),
            );
        });

        $this->app->singleton(ServiceAccess::class, static fn (Application $app): ServiceAccess => new ServiceAccess(
            $app->make(KeptAnswers::class),
            self::stringConfig($app->make(Config::class), 'billing-client.service_access.service', ''),
        ));

        $this->app->singleton(KeptSubscriptions::class, static fn (Application $app): KeptSubscriptions => new KeptSubscriptions(
            $app->make(KeptAnswers::class),
        ));
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

        $this->app->singleton(AllowanceSnapshots::class, static function (Application $app): CacheAllowanceSnapshots {
            $config = $app->make(Config::class);
            $prefix = self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client');

            return new CacheAllowanceSnapshots(
                self::cache($app, $config),
                $prefix.':snapshot:',
                self::intConfig($config, 'billing-client.snapshot_refresh', 300),
            );
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
        $this->app->alias(HttpBillingTransport::class, BillingTransport::class);
        $this->app->alias(HttpBillingTransport::class, FeatureReader::class);
        $this->app->singleton(HttpBillingTransport::class, static function (Application $app): HttpBillingTransport {
            $config = $app->make(Config::class);

            return new HttpBillingTransport(
                $app->make(HttpFactory::class),
                self::apiUrl($config),
                self::stringConfig($config, 'billing-client.api_token', ''),
                self::intConfig($config, 'billing-client.timeout', 5),
                self::intConfig($config, 'billing-client.connect_timeout', 2),
                self::intConfig($config, 'billing-client.lease_timeout', 2),
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
                snapshots: self::snapshotsFor($app, $config),
                queueRefill: self::stringConfig($config, 'billing-client.refill', 'queue') === 'sync'
                    ? null
                    : static function (string $org, string $meter) use ($app): void {
                        $app->make(BackgroundJobs::class)->dispatchOnce(RefillLease::claim($org, $meter), new RefillLease($org, $meter), RefillLease::UNIQUE_FOR);
                    },
                breaker: new RefillBreaker(
                    self::cache($app, $config),
                    self::intConfig($config, 'billing-client.refill_breaker', 30),
                    self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client').':refill-breaker',
                ),
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
                snapshots: self::snapshotsFor($app, $config),
                grace: self::graceFor($app, $config),
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
                self::apiUrl($config),
                self::stringConfig($config, 'billing-client.api_token', ''),
                self::intConfig($config, 'billing-client.timeout', 5),
                self::intConfig($config, 'billing-client.connect_timeout', 2),
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

            $this->commands([ReportUsageCommand::class, SweepReservationsCommand::class, SyncDefinitionsCommand::class]);
        }

        // Auto-load the ledger migration only when the database buffer is in use, so a
        // cache-buffer host is never handed a table it does not need.
        $config = $this->app->make(Config::class);

        if (self::stringConfig($config, 'billing-client.buffer', 'cache') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /** The last known allowance, wired only when the failure policy enforces it. */
    private static function snapshotsFor(Application $app, Config $config): ?AllowanceSnapshots
    {
        return FailurePolicy::fromConfig($config->get('billing-client.fail'))->enforcesLastKnown()
            ? $app->make(AllowanceSnapshots::class)
            : null;
    }

    /** The outage grace, wired only when the failure policy enforces the last known allowance. */
    private static function graceFor(Application $app, Config $config): ?OutageGrace
    {
        if (! FailurePolicy::fromConfig($config->get('billing-client.fail'))->enforcesLastKnown()) {
            return null;
        }

        $meters = [];
        $configured = $config->get('billing-client.grace.meters');

        foreach (is_array($configured) ? $configured : [] as $meter => $units) {
            if (is_string($meter) && (is_int($units) || (is_string($units) && is_numeric($units)))) {
                $meters[$meter] = (int) $units;
            }
        }

        return new OutageGrace(
            self::cache($app, $config),
            self::intConfig($config, 'billing-client.grace.units', 100),
            self::intConfig($config, 'billing-client.grace.window', 3600),
            $meters,
            self::stringConfig($config, 'billing-client.prefix', 'cbox-billing-client').':grace:',
        );
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

    /** The versioned API base every transport appends its paths to; see {@see ApiUrl}. */
    private static function apiUrl(Config $config): string
    {
        return ApiUrl::fromConfig($config);
    }

    /** `service_access.url` when set (an old root URL gets `/api/v1`), else the API URL. */
    private static function serviceAccessUrl(Config $config): string
    {
        $url = self::nullableStringConfig($config, 'billing-client.service_access.url');

        return $url === null ? self::apiUrl($config) : ApiUrl::fromLegacy($url);
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
