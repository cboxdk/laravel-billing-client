---
title: Multi-meter enforcement
weight: 25
description: Reserving a set of independent meters atomically — all-or-nothing across dimensions, each taken from its own local lease.
---

# Multi-meter enforcement

A single request often consumes several metered dimensions at once — API calls *and*
compute *and* egress. `reserve()` accepts a `[meter => estimate]` map to hold them
**together, all-or-nothing**: every meter is taken from its **own** local lease, and if
any one cannot be satisfied the whole set is rolled back and the reservation fails as a
unit. The single-meter call is the degenerate one-bucket case of the same path.

```php
use Cbox\Billing\Client\Facades\Billing;

$set = Billing::reserve('org_42', [
    'api.calls'   => 1,
    'compute.ms'  => 250,
    'egress.bytes' => 4096,
]);

// ... do the work, measuring the real usage ...

Billing::commit($set, [
    'api.calls'   => 1,
    'compute.ms'  => 210,
    'egress.bytes' => 3900,
]);
```

`reserve()` returns a `ReservationSet` (a single meter still returns a `Reservation`);
`commit()` takes a `[meter => actual]` map covering every held meter, and `release()`
returns the whole set.

## All-or-nothing, per dimension

Each meter is evaluated and held independently — the buckets are **never collapsed into
one number**. If the third meter is over its central allowance while the first two were
satisfiable, the two already-held slices are returned before the call throws, so a
partially-held reservation can never leak capacity:

```php
try {
    Billing::reserve('org_42', ['api.calls' => 1, 'compute.ms' => 999_999]);
} catch (\Cbox\Billing\Client\Exceptions\QuotaExceeded $e) {
    // compute.ms was exhausted; the api.calls units taken first were rolled back.
    // Neither meter buffered any usage.
}
```

The failure split is identical to the single-meter path: an exhausted allowance is a
semantic `QuotaExceeded` (always fails closed); an unreachable billing service is an
infrastructure fault resolved by the [failure policy](failure-policy.md) — a fail-open
bucket is admitted un-lease-backed and still buffers usage on commit.

## Settling a set

`commit()` validates **every** meter before mutating any, so a bad or missing actual
aborts the whole settle rather than half-applying it. Each meter returns its own
leftover to its own slice and buffers its own usage — dimensions stay isolated all the
way through reporting.
