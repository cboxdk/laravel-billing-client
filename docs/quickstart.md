---
title: Quickstart
weight: 1
description: Configure the billing endpoint and enforce a metered operation in a few lines.
---

# Quickstart

## 1. Install and configure

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

With a base URL and token set, the service provider binds the HTTP transport and the
`BillingClient` automatically.

## 2. Enforce on the hot path

```php
use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;

public function handle(BillingClient $billing): mixed
{
    try {
        $reservation = $billing->reserve(org: 'org_123', meter: 'api.calls', estimate: 1);
    } catch (QuotaExceeded) {
        abort(429, 'Usage limit reached.');
    }

    try {
        $result = $this->doTheWork();
        $billing->commit($reservation, actual: 1); // settle to what was actually used
        return $result;
    } catch (\Throwable $e) {
        $billing->release($reservation); // return the hold, charge nothing
        throw $e;
    }
}
```

`reserve` runs entirely locally when the leased slice covers the hold; it only touches
billing to refill the slice or when the allowance is exhausted.

Prefer a non-throwing pre-check? Use `can`:

```php
if (! $billing->can('org_123', 'api.calls', 1)) {
    abort(429, 'Usage limit reached.');
}
```

## 3. Report usage in the background

Committed usage is buffered durably and reported **cumulatively**. Schedule the flush:

```php
// routes/console.php or a scheduler
Schedule::command('billing:report-usage')->everyMinute();
```

A dropped report needs no special handling — because the report carries the running
total, the next flush backfills whatever was missed.

Next: [Core concepts](core-concepts/_index.md).
