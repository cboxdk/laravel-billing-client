# Changelog

All notable changes to `cboxdk/laravel-billing-client`. The package is 0.x: a minor
release may change behaviour, and every such change is listed with an upgrade note.

## 0.4.0

A behaviour release. Widen your constraint to `^0.4` and read the upgrade notes.

### Fixed

- **A revoked token no longer admits everything.** Client errors now carry their cause.
  A 4xx other than 408 and 429 raises `BillingRefused`, which fails closed under every
  failure policy; only `BillingUnavailable` (connection error, timeout, 5xx, 408, 429,
  malformed body) is resolved by the `fail` policy. Before, a 401 on a lease refill was
  treated as an outage and the default `allow` policy admitted every request.
- **A new organization can load its billing page.** `subscription($org)` returns `null`
  on the server's 404 for an organization with no subscription instead of throwing.
- **Unlimited meters stay unlimited.** An entitlement's `allowance: null` was read as `0`,
  so an unlimited meter looked exhausted. It is now `null`; `Entitlement::isUnlimited()`
  is new.
- **Lease expiry is read.** The server sends `expires_at` as ISO-8601; it was only read as
  an integer, so every real lease had no expiry. `LeaseGrant::$expiresAt` is now the
  millisecond epoch of that time.
- **A long hold is credited back once.** A reservation that outlived its TTL was given
  back twice, by the sweeper and again by the late `commit()`/`release()`, inflating the
  local slice past what billing leased. Whoever closes the hold first now owns the
  give-back.
- **`cancel()` defaults to the end of the period**, matching the server. It used to cancel
  immediately by default, cutting a paying customer off mid-period.

### Added

- `Exceptions\BillingRefused` and `Exceptions\BillingUnavailable`, both subclasses of
  `TransportException`, with read-only `endpoint`, `status`, `body` and `retryAfter`.
- `FakeBillingTransport::refuseWith($status)` and
  `FakeManagementTransport::refuseWith($status)`, to test a revoked token or rate limit.
- `FakeManagementTransport::requireProvisionedOrganizations()`, to refuse a subscribe or
  checkout for an organization that was never provisioned, as the server does.
- Recorded server responses in `tests/Fixtures/server`, a test that holds both the real
  transports and the fakes to them, and a contract suite (`composer test:contract`) that
  holds a live sandbox server to them. See `docs/getting-started/testing.md`.

### Changed

- The fakes are rebuilt as in-memory servers behind the real HTTP transports. They answer
  with the server's JSON and status codes, and the real transport parses them, so a fake
  can no longer return a shape the server never sends.

### Upgrade notes

- **Catching errors.** `catch (TransportException)` and `catch (BillingClientException)`
  still catch everything. To tell a refusal from an outage, catch `BillingRefused` and
  `BillingUnavailable`, and read `$e->status`, `$e->body` and `$e->retryAfter`.
- **Fail-open is narrower.** Under `fail = allow`, a refusal (401, 403, 404, 422, …) on a
  lease refill now throws `BillingRefused` from `reserve()`, and `can()` returns `false`.
  If a misconfigured token used to be masked by fail-open, it now surfaces.
- **`cancel($org)` now cancels at period end.** Pass `atPeriodEnd: false` for an immediate
  cancel. Calls that already pass `atPeriodEnd` explicitly are unaffected.
- **`subscription($org)` returns `null` for a 404.** Code that caught the exception to
  detect "no subscription" can drop the catch.
- **Custom `BillingTransport` implementations** must throw `BillingUnavailable` for an
  outage. A bare `TransportException` is no longer treated as an outage and fails closed.
- **Custom `ReservationRegistry` implementations**: `close(string $id)` now returns
  `bool`, true only for the one call that closed an open hold, and must claim it
  atomically.
- **`TransportException` constructor.** It now takes
  `(string $message, string $endpoint = '', ?int $status = null, ?string $body = null, ?int $retryAfter = null, ?Throwable $previous = null)`.
  Code that constructed it with `RuntimeException`'s `($message, $code, $previous)` must
  use the named factories (`status()`, `unreachable()`, `malformed()`) or the new order.
- **Fakes.** `FakeManagementTransport::subscribe()` returns `paymentIntent: null` for every
  plan, as the server does; a test that relied on the fake's invented intent for a paid
  plan must stub it. An immediate `cancel()` removes the subscription, so a following
  `subscription()` is `null`. `ensureOrganization()` without a `name` is a 422
  (`BillingRefused`). An unknown remote reservation on `commit()` is a 404
  (`BillingRefused`), not a `RuntimeException`. `takeDown()` now raises
  `BillingUnavailable`.
