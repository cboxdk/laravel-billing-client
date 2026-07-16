# Cbox Billing Client

**`cboxdk/laravel-billing-client`** — the app-local enforcement SDK a product app
embeds to bill against a remote Cbox Billing service. It enforces usage limits
**locally, on the hot path** — no network round-trip per request — while billing stays
the eventual authority.

## Install

```bash
composer require cboxdk/laravel-billing-client
```

```dotenv
# .env
BILLING_CLIENT_BASE_URL=https://billing.internal
BILLING_CLIENT_API_TOKEN=your-service-token
BILLING_CLIENT_LEASE_SIZE=100
BILLING_CLIENT_FAIL=allow
```

With a base URL and token set, the provider binds the HTTP transport and the
`BillingClient` automatically.

## Use

```php
use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;

public function handle(BillingClient $billing): mixed
{
    try {
        $reservation = $billing->reserve('org_123', 'api.calls', 1);
    } catch (QuotaExceeded) {
        abort(429, 'Usage limit reached.');
    }

    try {
        $result = $this->doTheWork();
        $billing->commit($reservation, actual: 1);
        return $result;
    } catch (\Throwable $e) {
        $billing->release($reservation);
        throw $e;
    }
}
```

Reserve several meters atomically by passing a `[meter => estimate]` map — all-or-nothing
across dimensions, each taken from its own local lease:

```php
$set = $billing->reserve('org_123', ['api.calls' => 1, 'compute.ms' => 250]);
$billing->commit($set, ['api.calls' => 1, 'compute.ms' => 210]);
```

Schedule the background usage flush and the abandoned-reservation sweep:

```php
Schedule::command('billing:report-usage')->everyMinute();
Schedule::command('billing:sweep-reservations')->everyFiveMinutes();
```

### Self-service management

A typed `BillingManagement` client (and `BillingManager` facade) lets a product app's
users manage their own billing over the management API:

```php
use Cbox\Billing\Client\Facades\BillingManager;

$plans   = BillingManager::plans();
$preview = BillingManager::previewChange('org_123', 'pro');
$result  = BillingManager::subscribe('org_123', 'pro');   // + payment intent if due
$usage   = BillingManager::usage('org_123');
```

Collect payment either way — redirect to a billing-hosted checkout/portal session, or drive
an embedded, gateway-agnostic element in your own UI. The SDK returns
`{gateway, publishableKey, clientSecret}`; the gateway JavaScript stays the product's
responsibility and settlement is confirmed by webhook:

```php
// Hosted (redirect):
$session = BillingManager::createCheckoutSession('org_123', 'pro', route('billing.done'));
return redirect()->away($session->url);

// Embedded (in-page element):
$intent = BillingManager::createPaymentIntent('org_123', amountMinor: 4_900, currency: 'usd');
// hand $intent->gateway / publishableKey / clientSecret to the front-end; handle SCA there
```

## How it works

Two tiers:

- **Hot path (local, no network).** A reservation takes units from a node-local *leased
  slice* of the organization's allowance via an atomic decrement-and-compensate.
- **Background (remote).** When the slice runs short the SDK leases a fresh slice from
  billing; committed usage is buffered durably and reported back **cumulatively**.

Leasing is **pessimistic** — billing reserves the granted units centrally — so an
organization can never exceed its allowance beyond a bounded overshoot of roughly
`lease_size × nodes`. Usage reporting is cumulative and **self-correcting**: a dropped
report is backfilled by the next flush, which carries the running total.

### Failure policy

Failure handling splits by cause:

- An **exhausted allowance** (billing granted zero) is a semantic hard limit —
  `QuotaExceeded`, always fail closed.
- An **unreachable billing service** is an infrastructure fault, resolved by the `fail`
  policy: `allow` admits best-effort (usage still buffered and reconciled later); `deny`
  refuses.

### More enforcement hardening

- **Reservation TTL recovery.** A held reservation a crashed request never settles is
  swept back to the local slice (`billing:sweep-reservations`), not leaked.
- **Single-flight refills.** A burst that empties a lease is coalesced behind a
  per-(org, meter) cache lock into one round-trip.
- **Durable buffer options.** Cache-backed by default, or a crash-safe `database` buffer
  (`buffer => 'database'`; publish the migration) that survives eviction and restart.
- **Observability signals.** `BillingSignals` (allowed / denied / refill / report) so a
  host can meter the meter; no-op by default, `LoggingBillingSignals` or your own metrics
  optional.

## Design

- **One network seam per surface.** `Contracts\BillingTransport` (enforcement) and
  `Contracts\ManagementTransport` (self-service) are the only things that touch the
  network — real `Http\*` implementations (bearer token, deny-by-default about responses)
  in production, `Testing\Fake*Transport` in tests.
- **Contracts-first, deny-by-default.** Depend on interfaces; unknown meters/plans are
  not entitled; malformed or non-2xx responses raise rather than being trusted.
- **Dogfooded testing.** `Testing\InteractsWithBillingClient` drives the whole two-tier
  flow and the management flow offline; the package's own suite uses it.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`. A cache store with atomic `increment` / `decrement`
(any Laravel driver) backs the local counters, the reservation registry, and the cache
usage ledger; the database buffer additionally uses `illuminate/database`.

## Documentation

See [`docs/`](docs/index.md) — overview, quickstart, core concepts (two-tier leasing,
multi-meter enforcement, reservation recovery, cumulative reporting, the failure policy,
the management client, and the architecture), and the self-service cookbook.

## License

MIT.
