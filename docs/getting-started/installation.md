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
| `base_url` | `BILLING_CLIENT_BASE_URL` | Billing service base URL. Empty → no HTTP transport is usable; bind your own. |
| `api_token` | `BILLING_CLIENT_API_TOKEN` | Bearer token for the transport. |
| `timeout` | `BILLING_CLIENT_TIMEOUT` | Request timeout in seconds (default 5). |
| `lease_size` | `BILLING_CLIENT_LEASE_SIZE` | Units leased per refill. Larger → fewer round-trips, more overshoot. |
| `refill_threshold` | `BILLING_CLIENT_REFILL_THRESHOLD` | Remaining balance at or below which a reserve tops the slice up. |
| `report_interval` | `BILLING_CLIENT_REPORT_INTERVAL` | Suggested cadence (seconds) for the reporter. |
| `fail` | `BILLING_CLIENT_FAIL` | `allow` (fail open) or `deny` (fail closed) on an outage. |
| `cache_store` | `BILLING_CLIENT_CACHE_STORE` | Cache store for the local counters/ledger (`null` = default). |
| `prefix` | `BILLING_CLIENT_PREFIX` | Cache key prefix. |

## Schedule usage reporting

Usage is reported off the hot path. Schedule the flush at your `report_interval`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:report-usage')->everyMinute();
```

## Bring your own transport

Without a base URL and token the HTTP transport has nothing to talk to. Bind your own
implementation of `Cbox\Billing\Client\Contracts\BillingTransport` in a service
provider to point the SDK at a different endpoint or to stub it out.
