---
title: Requirements
weight: 2
description: PHP, Laravel, and dependency versions the resolver enforces.
---

# Requirements

From `composer.json`:

| Requirement | Constraint |
| --- | --- |
| PHP | `^8.4` |
| `illuminate/contracts` | `^12.0 \|\| ^13.0` |
| `illuminate/http` | `^12.0 \|\| ^13.0` |
| `illuminate/support` | `^12.0 \|\| ^13.0` |

As a library, this package supports the current and previous Laravel majors so it
installs on either.

## Development

| Tool | Constraint |
| --- | --- |
| `larastan/larastan` | `^3.0` |
| `laravel/pint` | `^1.18` |
| `orchestra/testbench` | `^10.0 \|\| ^11.0` |
| `pestphp/pest` | `^3.5 \|\| ^4.0` |

## Runtime dependencies

The SDK needs a cache store that supports atomic `increment` / `decrement` (any
Laravel cache driver — array, Redis, Memcached, or database) for the node-local lease
counters and the durable usage ledger. For real usage buffering across a crash, point
the ledger at a persistent store rather than the array driver.
