---
title: Usage reporting
weight: 23
description: Why usage is buffered durably and reported as a cumulative running total that self-corrects after a dropped report.
---

# Usage reporting

## Append before counting

Every `commit` appends the used units to the durable `UsageBuffer` before they are
counted anywhere else. That ordering is the crash-safety story: if the process dies
after the append, the ledger still carries the usage; only usage produced after the last
durable append can be lost.

## Cumulative, not delta

The buffer holds a **monotonically increasing** total per `(org, meter)`, and the
`UsageReporter` sends that running total (`POST /api/v1/usage`), not a per-flush delta.
Billing keeps the **highest** cumulative it has seen per node and meter.

This makes reporting **self-correcting**:

1. Usage reaches 10. A report lands → billing knows 10.
2. Usage reaches 15, but that report is **dropped** (billing briefly unreachable).
   Billing still shows 10.
3. Usage reaches 20 and the next report lands → billing jumps straight to 20.

The dropped delta of 5 is backfilled automatically, because the running total already
includes it. There is nothing to reconcile by hand and nothing to drain on success — a
failed flush simply leaves the ledger untouched for the next attempt, so no usage is
ever lost to a failed report.

## Off the hot path

Reporting runs from the `billing:report-usage` command on your scheduler, at the
`report_interval` cadence. A missed run is harmless for the same reason a dropped report
is: the next run carries the full running total. Per-org faults are isolated — one
unreachable org does not block reporting for the others.
