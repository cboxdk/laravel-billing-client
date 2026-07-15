---
title: Getting started
weight: 10
description: Install the SDK, point it at a billing service, and test against the in-memory fake.
---

# Getting started

The package auto-registers `ClientServiceProvider`, which binds the node-local lease
store and durable usage buffer always, and the HTTP transport only when a base URL and
API token are configured.

- [Installation](installation.md) — install, publish config, and wire the scheduler.
- [Testing](testing.md) — the `InteractsWithBillingClient` trait and the fake transport.
