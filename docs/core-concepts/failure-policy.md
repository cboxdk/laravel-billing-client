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
under the default `allow` policy admitted every request.

See [Errors](errors.md) for the full classification.

## Infrastructure faults follow the policy

When the SDK can neither take from the local slice **nor** get an answer from billing to
refill (a connection error, a timeout, a 5xx, a 408 or a 429), that is an infrastructure
fault: `BillingUnavailable`. It is the only error the policy resolves, through a
per-deployment knob, `fail`:

- **`allow` (fail open, the default):** admit the request best-effort so a network blip
  does not throttle legitimate paid traffic. No local units were taken, so the returned
  reservation is *not* lease-backed — it will not wrongly credit the slice on commit —
  but the usage is still buffered durably and reconciled from the cumulative ledger once
  billing is reachable again.
- **`deny` (fail closed):** refuse — surface the `BillingUnavailable` — for strict
  tenants that would rather block than admit un-leased usage during an outage.

`can()` mirrors this: on an unreachable billing service it returns the policy's admit
decision (`true` under `allow`, `false` under `deny`); on an exhausted allowance or a
refusal it always returns `false`.

## Summary

| Situation | `allow` | `deny` |
| --- | --- | --- |
| Local slice covers the hold | proceeds | proceeds |
| Slice short, billing grants a refill | proceeds | proceeds |
| Slice short, allowance exhausted (semantic) | `QuotaExceeded` | `QuotaExceeded` |
| Slice short, billing refuses (401, 403, other 4xx) | `BillingRefused` | `BillingRefused` |
| Slice short, billing unavailable (connection, 5xx, 408, 429) | admitted best-effort | `BillingUnavailable` |

This mirrors the billing engine's own split between semantic denials (always closed) and
infrastructure faults (resolved by a deployment policy).
