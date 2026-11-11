---
title: Installation
weight: 11
description: Install the package, configure the billing endpoint, and schedule usage reporting.
---

# Installation

```bash
composer require cboxdk/laravel-billing-client
```

The provider is auto-discovered. Publish the config if you want to tune it in the repo:

```bash
php artisan vendor:publish --tag=billing-client-config
```

## Configuration

`config/billing-client.php` (all keys are environment-driven):

| Key | Env | Purpose |
| --- | --- | --- |
| `api_url` | `BILLING_CLIENT_API_URL` | Billing's API with its version: `https://api.cboxbilling.com/v1` (the default when nothing is set), or `https://<api host>/v1`. Every call appends its path (`…/v1/leases`). |
| `base_url` | `BILLING_CLIENT_BASE_URL` | **Deprecated.** The Billing app root (`https://app.cboxbilling.com`); the client calls `{base_url}/api/v1`. Used only when `api_url` is empty. |
| `api_token` | `BILLING_CLIENT_API_TOKEN` | Bearer token for the transport. |
| `timeout` | `BILLING_CLIENT_TIMEOUT` | Request timeout in seconds (default 5). |
| `lease_size` | `BILLING_CLIENT_LEASE_SIZE` | Units leased per refill. Larger → fewer round-trips, more overshoot. |
| `refill_threshold` | `BILLING_CLIENT_REFILL_THRESHOLD` | Remaining balance at or below which a reserve tops the slice up. |
| `report_interval` | `BILLING_CLIENT_REPORT_INTERVAL` | Suggested cadence (seconds) for the reporter. |
| `fail` | `BILLING_CLIENT_FAIL` | Outage policy: `last_known` (default, enforce the last known allowance), `allow` (fail open) or `deny` (fail closed). See [Failure policy](../core-concepts/failure-policy.md). |
| `snapshot_refresh` | `BILLING_CLIENT_SNAPSHOT_REFRESH` | Seconds between entitlement reads for `last_known` (default 300). |
| `cache_store` | `BILLING_CLIENT_CACHE_STORE` | Cache store for the local counters/ledger (`null` = default). |
| `prefix` | `BILLING_CLIENT_PREFIX` | Cache key prefix. |

## Schedule usage reporting

Usage is reported off the hot path. Schedule the flush at your `report_interval`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:report-usage')->everyMinute();
```

## Bring your own transport

With no URL configured, the HTTP transports call the hosted API
(`https://api.cboxbilling.com/v1`), and without a token Billing refuses every call. To run
without Billing, or to point the SDK at something else, bind your own implementation of
`Cbox\Billing\Client\Contracts\BillingTransport` (and `ManagementTransport`) in a service
provider, or use the fakes in tests.

## Upgrading to 0.6

Billing v2 serves its API on its own host at `/v1` and gives every object that leaves it a
public id: `{prefix}_` plus 22 base62 characters (`inv_…`, `sub_…`, `lir_…`, `apr_…`).

0.6 works against Billing before v2 too: an id sent as a number (`17`) arrives as the string
`"17"`, and the old `BILLING_CLIENT_BASE_URL` keeps working. Upgrade the app first; Billing v2
can deploy after.

1. Bump the constraint: `"cboxdk/laravel-billing-client": "^0.6"`.
2. Change env:

   ```dotenv
   # before
   BILLING_CLIENT_BASE_URL=https://app.cboxbilling.com
   # after
   BILLING_CLIENT_API_URL=https://api.cboxbilling.com/v1
   ```

   Until you change it, the old setting keeps working: the client calls
   `{BILLING_CLIENT_BASE_URL}/api/v1`, which the Billing app host still answers with
   `Deprecation` and `Link` headers. A `BILLING_CLIENT_ACCESS_URL` (or
   `CBOX_SERVICE_ACCESS_URL`) that does not end in `/v1` is read the same way.
3. Treat ids as strings. `IssuedInvoice::$id`, `SubscriptionChange::$id`, `LimitIncrease::$id`,
   `ResourceLimit::$grantRequestId` and `InvoiceRefundResult::$approvalId` are strings, and
   `cancelLimitIncrease()` takes one. If your app stores any of them, make the column a string
   (`varchar(64)` is enough) and re-read the rows from Billing: a numeric id gets a 404.
4. `TransportException::$endpoint` is now the path under the API URL (`/leases`, not
   `/api/v1/leases`). Update anything that matches on it.
5. Code that calls Billing itself (outside this client) builds its URLs on
   `Cbox\Billing\Client\Http\ApiUrl::fromConfig(config())`, the URL the client uses, instead of
   `config('billing-client.base_url').'/api/v1'`. Keep `BILLING_CLIENT_BASE_URL` set until no code
   reads `billing-client.base_url` any more: `api_url` wins when both are set.
6. Code that reads Billing's webhooks or events directly (outside this client) must dedupe on
   the new string event ids and read `dispute.*` ids as `dsp_…`.
