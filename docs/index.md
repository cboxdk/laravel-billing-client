---
title: Overview
weight: 0
description: An app-local enforcement SDK that leases allowance from a remote Cbox Billing service and enforces hard limits on the hot path, with no per-request network round-trip.
---

# Cbox Billing Client

`cboxdk/laravel-billing-client` is the SDK a product app embeds to bill against a
remote Cbox Billing service. It enforces usage limits **locally, on the hot path** —
no network call per request — while billing stays the eventual authority.

## Mental model

Metering is split into two tiers:

- **Tier 1 — the hot path (local, no network).** A reservation takes units from a
  node-local *leased slice* of the organization's allowance via an atomic
  decrement-and-compensate. This is what runs on every request, so it never blocks on
  billing.
- **Tier 2 — the background (remote).** When the local slice runs short, the SDK
  leases a fresh slice from billing. Committed usage is appended to a durable buffer
  and reported back **cumulatively** on a schedule.

Because leasing is **pessimistic** — billing reserves the granted units centrally —
an organization can never exceed its allowance beyond a bounded overshoot of roughly
`lease_size × nodes` (the leased-but-unused units stranded across nodes). The only
other drift is reporting lag, which the cumulative, self-correcting report closes.

## What you get

- A `BillingClient` service (and `Billing` facade) with `reserve` / `commit` /
  `release` / `can`.
- A single network seam, `Contracts\BillingTransport`, with a real HTTP
  implementation and an in-memory fake for tests.
- Configurable fail-open / fail-closed behaviour for the moment the SDK can neither
  lease locally nor reach billing.

## Sections

- [Getting started](getting-started/_index.md) — install, configure, and test.
- [Core concepts](core-concepts/_index.md) — the two-tier lease, cumulative
  reporting, the failure policy, and the architecture.

> This package is the enforcement **client**. The metering authority, allowance
> accounting, and ingest live in the deployable Cbox Billing service, which this SDK
> talks to over the documented HTTP API.
