---
title: Sponsored provider capacity
weight: 29
description: Durable capacity claims backed by an operator-approved finite agreement, separate from customer plans and local leases.
---

# Sponsored provider capacity

`Contracts\SponsoredCapacityTransport` is a thin HTTP client for Billing's durable
provider ledger. It does not grant a customer subscription, lease local allowance,
collect money or provision funding. An operator must first approve and provision a
finite agreement in Billing. Use the provider's product-bound token with explicit
`capacity:read` and `capacity:write` scopes.

The container binds the contract using `billing-client.api_url`, `api_token`,
`timeout` and `connect_timeout`. Nothing changes on the existing `BillingClient`
or `ManagementTransport` contracts. A server without these endpoints fails closed.

Set `BILLING_CLIENT_API_URL` to the deployment's actual API root. Hosted Billing
currently uses `https://app.cboxbilling.com/api/v1`; its separate
`https://api.cboxbilling.com/v1` host still redirects pending its infrastructure
rollout. This transport refuses redirects rather than forwarding credentials.

```php
use Cbox\Billing\Client\Contracts\SponsoredCapacityTransport;

$capacity = app(SponsoredCapacityTransport::class);
// Persist these keys and the exact input before the first call.
$claim = $capacity->reserve($agreement, $claimKey, $org, $generationKey, $upperBound);
// A replay can be terminal. Both claim and allocation state must be inspected.
// After storing bytes, with actual <= upperBound:
$settled = $capacity->commit($agreement, $claimKey, $actual);
```

## Operations and returned values

| Method | Result | HTTP path relative to the configured API root |
| --- | --- | --- |
| `reserve(agreement, claim, org, allocation, amount)` | `CapacityClaim` | `PUT /capacity-agreements/{agreement}/claims/{claim}` |
| `claim(agreement, claim)` | `CapacityClaim` | `GET` the same path |
| `commit(agreement, claim, actual)` | `CapacityClaim` | `POST` the claim's `/commit` |
| `cancel(agreement, claim)` | `CapacityClaim` | `POST` the claim's `/cancel` |
| `allocation(agreement, allocation)` | `CapacityAllocation` | `GET /capacity-agreements/{agreement}/allocations/{allocation}` |
| `release(agreement, allocation, deletion)` | `CapacityAllocation` | `POST` the allocation's `/release` |

`CapacityClaim` contains `agreement`, `key`, `state` and a typed `allocation`.
`CapacityAllocation` contains `agreement`, `key`, canonical Billing `org`,
`estimate`, nullable `actual`, `state` and nullable `deletion`. Claim states are
`reserved`, `committed`, `canceled`; allocation states also include `released`.
A committed claim on a released allocation is historical evidence, not permission
to recreate bytes. The HTTP responses are direct objects, not a `data` wrapper.
Malformed, mismatched or unsafe successful replies throw `BillingUnavailable`.

Keys are 1–128 ASCII letters, digits, underscores or hyphens. Amounts are integers:
`amount` is 1…9,007,199,254,740,991 and `actual` is 0…9,007,199,254,740,991, no greater
than the held estimate. These bounds are serialization limits, not a granted budget.

## Ownership and recovery

The provider, not this SDK, must enforce physical storage bounds and deletion.
Reserve separate staging capacity for every upload attempt, and final-generation
capacity before copying. Hold both during overlap. Only final physical generations
deduplicate; a content hash cannot serve as a reusable allocation key after deletion.
Additional temporary copies require additional holds. Customer current-original
quota is independent of all physically retained provider bytes.

Cancel only after reserved staging is safely aborted/deleted. Cancel does not free
another claim's hold or committed bytes. Release a committed allocation only after
physical deletion and draining pending claims. Retained accounting refunds actual
once; consumable accounting never refunds committed units.

There are no automatic retries, redirects, TTL expiry, cached grants or fail-open
policy. On timeout retain uncertainty, look up and replay the **same key and input**.
A GET/cancel 404 does not fence an in-flight reserve: after safe multipart cleanup,
replay the identical reserve and then cancel. Never replace an ambiguous key or
fall back to the customer's standalone plan. Expiry/revocation blocks new work
but permits existing reconciliation, cancellation and release.

`409 consumer_capacity_exhausted` throws `BillingRefused`; `503
consumer_capacity_unavailable`, connection failures, malformed responses and 429
throw `BillingUnavailable`. Both expose `status`, `body` and `retryAfter` through
`TransportException`. Read `error` from a JSON body when present; shared auth,
proxies and infrastructure can return other shapes. Do not branch on prose.

## Tests and rollout

`Testing\FakeSponsoredCapacityTransport` queues typed claims, allocations or
`TransportException` instances in `responses`, and records calls. The existing
`InteractsWithBillingClient::capacityTransport()` returns this fake. Bind it with
`app()->instance(SponsoredCapacityTransport::class, $fake)`. An empty queue throws
`BillingUnavailable`; the fake does not simulate or silently fund a ledger.

Server deployment, this client's release, the provider integration and approved
finite production funding are separate prerequisites. Testing the transport does
not prove storage deletion, multipart size enforcement or a production integration.
