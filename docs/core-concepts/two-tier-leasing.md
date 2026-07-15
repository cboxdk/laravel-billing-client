---
title: Two-tier leasing
weight: 22
description: How local hard-limit enforcement is backed by pessimistic remote leases, and why overshoot is bounded.
---

# Two-tier leasing

## Why two tiers

Calling billing on every metered request would put the network on the hot path — slow,
and a hard dependency on billing being up. Instead the SDK **leases a slice** of the
organization's allowance ahead of time and enforces against that slice locally.

- **Tier 1 (local):** `reserve` takes units from the node-local `LocalLeaseStore` with
  an atomic decrement-and-compensate. No network.
- **Tier 2 (remote):** when the slice is short, the `LeaseManager` leases a fresh slice
  (`POST /api/v1/leases`) sized to at least `lease_size`.

## Pessimistic leasing is what makes the hard limit real

A granted lease **reserves** those units from the central budget, so the sum of all
outstanding leases can never exceed the organization's remaining allowance. That single
property is what lets each node enforce a hard limit locally without a shared hot store:
the store only needs single-node atomicity; cross-node correctness comes from the lease,
not from coordination.

When billing grants **zero** on a refill, the allowance is exhausted — a **semantic**
denial (`QuotaExceeded`), the hard limit. This is distinct from billing being
*unreachable*, which is an infrastructure fault (see [Failure policy](failure-policy.md)).

## Bounded overshoot

The cost of skipping the per-request round-trip is a little slack: a node may hold
leased-but-unused units. The worst-case overshoot beyond the exact allowance is roughly:

```
overshoot ≈ lease_size × number_of_nodes
```

Tune it with `lease_size`: smaller slices tighten the limit and cost more refills;
larger slices cut round-trips and loosen it. This overshoot is **accepted and
documented** — it never lets an organization exceed its allowance by more than the
outstanding leased slices, and unused leases are returned to the central budget as they
expire.

## Refilling before you run dry

`refill_threshold` lets a successful reserve opportunistically top the slice up when the
remaining balance is low, so the *next* reserve rarely blocks on the network. The top-up
is best-effort: if billing is briefly unreachable it is simply deferred to the next
authoritative refill.

## Single-flight refills

When a burst of concurrent requests empties a slice at once, every one of them would try
to refill — a thundering herd of identical lease round-trips. The `LeaseManager` guards
a refill with a per-(org, meter) cache lock, so the burst is **coalesced into one**
round-trip: the first caller leases, and the others wait, then re-check the slice and
**reuse** the freshly-leased units instead of issuing their own. Tune the lock with
`refill_lock_ttl` (how long a refill may hold it) and `refill_lock_wait` (how long a
waiter blocks before falling back to a direct refill). This needs a cache store that
supports atomic locks (Redis/Memcached/database/array); on a store that cannot lock, the
refill simply runs directly — correct, just not coalesced.
