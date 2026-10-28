---
title: Errors
weight: 28
description: BillingRefused versus BillingUnavailable, what each carries, and which one the failure policy resolves.
---

# Errors

Every fault talking to billing is a `TransportException`. Since 0.4.0 it is always one of
two subclasses, chosen by **cause**:

| Exception | When | Failure policy |
| --- | --- | --- |
| `BillingRefused` | billing answered with a 4xx other than 408 and 429 | never fails open |
| `BillingUnavailable` | a connection error or timeout, a 5xx, a 408, a 429, or a 2xx with a malformed body | `allow` admits, `deny` throws |

`QuotaExceeded` (an exhausted allowance) is separate and always fails closed. Code that
catches `TransportException` or `BillingClientException` keeps catching both subclasses.

## What each carries

Both subclasses expose the same read-only fields:

| Field | Type | Meaning |
| --- | --- | --- |
| `endpoint` | `string` | the API path, e.g. `/api/v1/leases` |
| `status` | `?int` | the HTTP status; `null` when no response arrived |
| `body` | `?string` | the raw response body, e.g. `{"error":"Invalid API token."}` |
| `retryAfter` | `?int` | the `Retry-After` header in seconds (delta-seconds or an HTTP-date) |

```php
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;

try {
    $management->subscribe($org, $plan);
} catch (BillingRefused $e) {
    // Billing said no. 404 is an unknown plan or organization; 422 a validation error.
    report_to_user($e->status, $e->body);
} catch (BillingUnavailable $e) {
    // Try again later; honour $e->retryAfter on a 429.
    retry_after($e->retryAfter ?? 30);
}
```

## Common statuses

| Status | Class | Typical cause |
| --- | --- | --- |
| 401 | `BillingRefused` | missing, wrong or revoked API token |
| 403 | `BillingRefused` | the token may not act for this organization |
| 404 | `BillingRefused` | unknown organization, plan, reservation or payment method |
| 409 | `BillingRefused` | the request conflicts with the subscription's state |
| 422 | `BillingRefused` | validation failed; `body` carries `errors` |
| 429 | `BillingUnavailable` | rate limited; `retryAfter` says when to retry |
| 5xx | `BillingUnavailable` | billing is failing |
| none | `BillingUnavailable` | connection error or timeout; `getPrevious()` holds the cause |

## The one 404 that is not an error

`subscription($org)` returns `null` when billing answers 404. That is the normal state of
an organization that has never subscribed, and the page it subscribes from has to load.
Every other refusal from that call still throws.
