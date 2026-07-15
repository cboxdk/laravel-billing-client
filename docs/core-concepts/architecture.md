---
title: Architecture
weight: 21
description: The single network seam and the local collaborators that make up the SDK.
---

# Architecture

The SDK is contracts-first: everything the app depends on is an interface, and the only
component that touches the network is the transport.

## The seam

`Contracts\BillingTransport` maps one-to-one onto the billing HTTP API:

| Method | Endpoint |
| --- | --- |
| `lease($org, $meter, $size)` | `POST /api/v1/leases` |
| `reportUsage($org, $entries)` | `POST /api/v1/usage` |
| `reserve($org, $meters)` | `POST /api/v1/reserve` |
| `commit($reservationId, $actuals)` | `POST /api/v1/commit` |
| `entitlements($org)` | `GET /api/v1/entitlements/{org}` |

`Http\HttpBillingTransport` speaks real HTTP with a bearer token and is
**deny-by-default** about responses: any non-2xx, connection error, or malformed body
raises a `TransportException` rather than fabricating a success.
`Testing\FakeBillingTransport` implements the same contract in memory.

## Local collaborators

- **`Contracts\LocalLeaseStore`** (`Stores\CacheLeaseStore`) — the node-local leased
  balance per `(org, meter)`. Uses only atomic cache `increment` / `decrement`;
  `tryTake` is a decrement-and-compensate, so it can only ever over-reject, never
  over-grant.
- **`Contracts\UsageBuffer`** (`Buffers\CacheUsageBuffer`, `Buffers\ArrayUsageBuffer`) —
  the durable ledger every committed unit is appended to before it is counted anywhere
  else, holding a monotonic cumulative total per `(org, meter)`.
- **`Leasing\LeaseManager`** — acquires and refills leased slices from the transport
  into the store.
- **`Reporting\UsageReporter`** — ships the cumulative ledger to billing in the
  background.
- **`BillingClient`** — the façade over all of the above, exposing `reserve` /
  `commit` / `release` / `can`.

## Flow of a reservation

1. `BillingClient::reserve` asks the `LocalLeaseStore` to `tryTake` the estimate.
2. On a short slice it asks the `LeaseManager` to `refill` from the transport, then
   takes again.
3. `commit` returns the unused tail to the slice and records usage in the `UsageBuffer`.
4. The `UsageReporter`, on a schedule, flushes the cumulative totals to billing.

No third-party product is named or required; the design is described in our own terms.
