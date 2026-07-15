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

Schedule the background usage flush:

```php
Schedule::command('billing:report-usage')->everyMinute();
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

## Design

- **One network seam.** `Contracts\BillingTransport` is the only thing that touches the
  network — `Http\HttpBillingTransport` (bearer token, deny-by-default about responses)
  in production, `Testing\FakeBillingTransport` in tests.
- **Contracts-first, deny-by-default.** Depend on interfaces; unknown meters are not
  entitled; malformed responses raise rather than being trusted.
- **Dogfooded testing.** `Testing\InteractsWithBillingClient` drives the whole two-tier
  flow offline; the package's own suite uses it.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`. A cache store with atomic `increment` / `decrement`
(any Laravel driver) backs the local counters and usage ledger.

## Documentation

See [`docs/`](docs/index.md) — overview, quickstart, and core concepts (two-tier
leasing, cumulative reporting, the failure policy, and the architecture).

## License

MIT.
