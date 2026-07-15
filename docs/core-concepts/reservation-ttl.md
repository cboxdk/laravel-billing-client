---
title: Reservation TTL & recovery
weight: 26
description: How a held reservation expires and is swept back to the local slice so a crashed request never leaks leased capacity.
---

# Reservation TTL & recovery

A reservation takes units from the local slice on `reserve()` and returns the leftover
on `commit()`/`release()`. But what if the request that held them never gets to commit —
the worker is killed, the process crashes, an exception skips the release? Without
recovery those units would sit taken for the rest of the period, silently shrinking the
node's effective allowance every time it happens.

Every lease-backed reservation is therefore recorded in a durable **reservation
registry** with a TTL. A held reservation that is never settled is **swept back to its
local slice** once the TTL passes, so a crashed request costs at most one TTL of
stranded capacity instead of leaking it permanently.

## The lifecycle

- `reserve()` takes the units **and** records the hold (per-meter lease-backed amounts +
  an expiry) in the registry.
- `commit()` / `release()` settle the units **and** close the registry entry — a settled
  reservation is never swept.
- The `billing:sweep-reservations` command reclaims every hold past its TTL, returning
  its units to the local slice and dropping it from the registry.

```text
reserve ──▶ [registry: hold, expires at T+ttl]
   │
   ├─ commit/release ──▶ close entry               (normal path)
   │
   └─ (crash, no settle) ──▶ sweep after T+ttl ──▶ units returned to slice
```

Reclaiming only credits the **local** slice — it never touches billing's central lease,
which the cumulative [usage report](usage-reporting.md) reconciles independently. Only
lease-backed holds are recorded; a fail-open admission took no local units, so there is
nothing to reclaim.

## Configuring and scheduling

`reservation_ttl` (seconds, default 300) sets how long a hold lives. Schedule the sweep
at roughly that cadence so leaked capacity is bounded by one sweep interval:

```php
// routes/console.php
Schedule::command('billing:sweep-reservations')->everyFiveMinutes();
```

The default registry is cache-backed and lives beside the lease counters, so both
survive a crashed request. Point it at a **persistent** cache in production so a process
restart cannot lose a pending hold before it is swept.
