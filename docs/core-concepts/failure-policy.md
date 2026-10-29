---
title: Failure policy
weight: 24
description: How the SDK resolves a request when it can neither take locally nor reach billing, split by cause.
---

# Failure policy

Failure handling splits by **cause**, never uniformly.

## Semantic denial always fails closed

When billing is reachable but the central allowance is **exhausted** (a refill grants
zero), the request is refused with `QuotaExceeded`. This is a decision — the hard limit
— and it fails closed regardless of any policy setting. An exhausted allowance is not an
outage.

## A refusal always fails closed

When billing answers a refill with a 4xx (other than 408 and 429), it has made a decision:
the token is revoked or wrong (401), it may not act for this organization (403), or the
request is invalid (404, 409, 422). The SDK raises `BillingRefused` and fails closed under
**every** policy. Before 0.4.0 a refusal was mistaken for an outage, so a revoked token
under the then-default `allow` policy admitted every request.

See [Errors](errors.md) for the full classification.

## Infrastructure faults follow the policy

When the SDK can neither take from the local slice **nor** get an answer from billing to
refill (a connection error, a timeout, a 5xx, a 408 or a 429), that is an infrastructure
fault: `BillingUnavailable`. It is the only error the policy resolves, through a
per-deployment knob, `fail`:

- **`last_known` (the default since 0.4.1):** enforce the allowance the client last knew.
  The request is admitted while it fits in the headroom and refused with `QuotaExceeded`
  past it, on a hard-limit meter. A `bill`-overage or unlimited meter keeps admitting.
- **`allow` (fail open):** admit every request, even past the allowance, so an outage
  never throttles traffic.
- **`deny` (fail closed):** refuse with `BillingUnavailable` as soon as the local lease is
  spent.

Under every policy an admitted outage request is *not* lease-backed, so it never credits
the slice on commit, and its usage is buffered durably and reported once billing is
reachable again. Reporting is cumulative, so nothing is counted twice.

## How `last_known` works

On a lease refill the client also reads the organization's entitlements, at most once
per `snapshot_refresh` seconds (default 300), and keeps them with a count of the units
billing has leased to it. Billing caps the sum of its leases at the allowance, so that
count is what billing considers used, including any lease not yet spent. During an
outage:

- The unspent lease is spent first, through the normal path.
- Past it, a request is admitted while `allowance − leased − admitted during the outage`
  covers it. A release or a smaller commit gives the unused units back.
- A meter with no known entitlement cannot be enforced, so it refuses with
  `BillingUnavailable`, as `deny` does. A disabled meter refuses with `QuotaExceeded`.
- `can()` answers the same way, without taking anything.
- A refusal emits the `denied` signal with reason `outage_limit`.

Take a free organization with a 1,000,000-span block meter and a 100,000-span lease. It
has used 300,000 and holds 100,000 unspent when billing goes down, so 400,000 are leased.
It can spend the 100,000 in the slice, then 600,000 more, and is refused at 1,000,000.

### Limits

- **It knows what this client saw.** Clients that share the cache store (the
  `cache_store` setting) share the count. A client with its own store does not see the
  leases other clients took, so across separate stores the overshoot during an outage is
  up to what the others have leased.
- **Period boundaries are inferred.** The client has no billing period. When billing
  grants a full lease that would take the count past the allowance, the client takes it
  as a new period and counts only that lease.
- **The snapshot is as old as the last refill.** A plan change during the outage is not
  seen until billing is back.

`can()` mirrors the policy: on an unreachable billing service it answers from the last
known headroom under `last_known`, `true` under `allow`, and `false` under `deny`. On an
exhausted allowance or a refusal it always returns `false`.

## Summary

| Situation | `last_known` | `allow` | `deny` |
| --- | --- | --- | --- |
| Local slice covers the hold | proceeds | proceeds | proceeds |
| Slice short, billing grants a refill | proceeds | proceeds | proceeds |
| Slice short, allowance exhausted (semantic) | `QuotaExceeded` | `QuotaExceeded` | `QuotaExceeded` |
| Slice short, billing refuses (401, 403, other 4xx) | `BillingRefused` | `BillingRefused` | `BillingRefused` |
| Slice short, billing unavailable, within the last known allowance | admitted | admitted | `BillingUnavailable` |
| Slice short, billing unavailable, past the last known allowance (block meter) | `QuotaExceeded` | admitted | `BillingUnavailable` |
| Slice short, billing unavailable, `bill` or unlimited meter | admitted | admitted | `BillingUnavailable` |
| Slice short, billing unavailable, no known entitlement | `BillingUnavailable` | admitted | `BillingUnavailable` |

This mirrors the billing engine's own split between semantic denials (always closed) and
infrastructure faults (resolved by a deployment policy).
