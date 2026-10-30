# Changelog

All notable changes to `cboxdk/laravel-billing-client`. The package is 0.x: a minor
release may change behaviour, and every such change is listed with an upgrade note.

## Unreleased

Additive: no existing signature or default changes, and the client still talks to an older server.

### Added

- `BillingManagement::upsertOrganization()`: `ensureOrganization()` with billing's answer, as an
  `OrganizationProfile` (billing email, address, VAT number and its verdict, and whether the
  account is shared). A new `OrganizationProfileTransport` contract, so an older custom transport
  keeps compiling; it gets an `UnsupportedOperation`.
- `tax_id` on the organization upsert, for a server that takes it.
- `FakeManagementTransport` presents the address, `tax_id` and `account` on the organization.

## 0.4.5

A patch release. Everything is additive: no existing signature or default changes, and the
client still talks to an older server.

### Added

- **Plan features.** `Plan` gains optional trailing `features` (`array<string, int|string|bool|null>`),
  `product` (the product key) and `taxInclusive` (whether the seller quotes the price including
  tax), read from `GET /api/v1/plans`. Billing lists every feature any plan of the product grants,
  deny-by-default: `false` not granted, `true` a granted boolean feature, the value of a granted
  config feature. Cache the catalog instead of hard-coding limits.
- `Plan::feature(string $key)` (the value, `null` when unlisted), `Plan::hasFeature(string $key)`
  and `Plan::featureInt(string $key)` (an integer limit, `0` when not granted).
- `FakeManagementTransport` presents `product`, `features` and `price.tax_inclusive` for the plans
  it is seeded with.
- Docs: caching the catalog and refreshing it on Billing's `plan.changed` webhook. The package
  does not receive webhooks; your app verifies and routes them.

### Upgrade notes

- Nothing to change. A server without features gives an empty `features` map, a `null` `product`
  and a `null` `taxInclusive`.
- The recorded `plans` server fixture now carries `product`, `features` and `price.tax_inclusive`.

## 0.4.4

A patch release. Everything is additive: no existing signature or default changes, and the
client still talks to an older server.

### Added

- **Cross-product bundles.** `BillingManagement::bundles(string $org, ?string $product = null,
  ?string $locale = null): OrganizationBundles` reads `GET /api/v1/organizations/{org}/bundles`:
  the benefits on the organization's subscriptions (`active`, a list of `ActiveBundle`) and what it
  could add to save (`offers`, a list of `BundleOffer` of kind `add_product`, `unlock` or
  `multi_product`, with `BundleOfferPlan`s carrying prices and a checkout link). Copy comes
  ready to render in the requested language.
- **Cancel consequences.** `BillingManagement::previewCancel(string $org, bool $atPeriodEnd = true,
  ?string $locale = null): CancelPreview` calls `POST /api/v1/subscriptions/{org}/cancel/preview`:
  when the subscription ends and the `BundleConsequence`s on the organization's other
  subscriptions (lost or reduced, from when, the price before and after, a `message` to show).
  `ChangePreview` gains an optional `consequences` list for a downgrade that ends a benefit.
- `Subscription` gains optional trailing `trialEndsAt`, `pendingChange` (a `PendingChange` with
  the plan key and `effectiveAt`) and `bundle` (a `SubscriptionBundle`), plus `isTrialing()`.
- `SubscriptionChange` gains optional trailing `reference` (what inside the organization it is for,
  such as a Sites website) and `bundle`.
- `Contracts\BundleTransport`, implemented by `HttpManagementTransport` and
  `FakeManagementTransport`. `BillingManagement` throws `UnsupportedOperation` for the two new
  calls on a transport that does not implement it.
- `FakeManagementTransport::withBundles()` and `withConsequences()`, and the fake presents
  `trial_ends_at`, `pending_change`, `reference` and `bundle` from what it was seeded with.

### Upgrade notes

- Nothing to change. Apps that read `trial_ends_at` or `pending_change` with a raw HTTP call can
  use `Subscription::$trialEndsAt` and `Subscription::$pendingChange` instead.
- The recorded server fixtures now carry `bundle`, `pending_change` and `consequences`, and two new
  fixtures record the bundles endpoint and the cancel preview.

## 0.4.3

A patch release. Everything is additive: no existing signature or default changes, and the
client still talks to an older server, which ignores the new fields.

### Added

- **Purchaser type and public-sector invoice data on the organization upsert.**
  `ensureOrganization()` documents three optional attributes:
  - `purchaser_type`: who the customer buys as, where that can exempt the sale. The values
    are the tax engine's `PurchaserType` (`state_or_local_government`,
    `charitable_organization`, `diplomatic_or_consular`, `public_body`, …). It asks the
    place's own rule and never exempts by itself; most rules also need a verified exemption
    certificate on file.
  - `ean_number`: the 13-digit EAN location number (GLN) a Danish public body is invoiced
    to. Billing validates the GS1 check digit and holds a DK public body's invoices until
    it is set.
  - `order_reference`: the purchase order or contact person printed on every invoice.
- `FakeManagementTransport` stores and presents the three fields and mirrors the server's
  validation: an unknown purchaser type or an EAN with a wrong check digit is a 422.

## 0.4.2

A patch release. Everything is additive: no existing signature or default changes, and the
client still talks to an older server.

### Added

- **The subscription change feed.** `BillingManagement::subscriptions(?DateTimeInterface
  $updatedSince = null, ?string $cursor = null, ?int $limit = null): SubscriptionPage` reads
  `GET /api/v1/subscriptions`. A page has `data` (a list of `SubscriptionChange`), `hasMore`
  and `nextCursor`. `allSubscriptions(?DateTimeInterface $updatedSince = null, ?int
  $pageSize = null)` returns a generator that reads every page as you iterate. Use it to sync
  every subscriber's plan and status in a few calls instead of one call per organization.
- `ValueObjects\SubscriptionChange`, one feed row. `id` and `organizationId` are Billing's
  ids; key your records on `organizationRef`, your own tenant key. `organizationKey()`
  falls back to Billing's id for an organization provisioned without one.
- **Reactivate.** `BillingManagement::reactivate(string $org): Subscription` calls
  `POST /api/v1/subscriptions/{org}/reactivate`. It resumes a pause, undoes a period-end
  cancel, or brings back a subscription canceled within the win-back window. An active
  subscription is a 409 and no subscription a 404, both as `BillingRefused`.
- **Discounts, pauses and the organization key on a subscription.** `Subscription` gains
  optional trailing arguments `discount` (`?SubscriptionDiscount`), `organizationRef`,
  `pauseReason` and `paused`, plus `isPaused()`. `ChangePreview` gains an optional
  `discount`. The new `ValueObjects\SubscriptionDiscount` has `code`, `duration`, `name`,
  `percentOff`, `amountOffMinor`, `currency` and `endsAt`, plus `isPercentage()` and
  `isForever()`. An older server omits these fields; they read as null and false.
- `Contracts\SubscriptionTransport`, the contract for the feed and reactivate, implemented
  by `HttpManagementTransport` and `FakeManagementTransport`.
- `Exceptions\UnsupportedOperation`, thrown by `BillingManagement` when its transport does
  not implement `SubscriptionTransport`.
- `FakeManagementTransport` serves the feed (paged, filtered by `updated_since`, canceled
  subscriptions included) and reactivate, and presents the discount, `organization_ref` and
  `pause_reason`. `withSubscription()` takes an optional third argument, when the row last
  changed.

### Fixed

- The `BillingManager` facade docblock now says `changePlan()` returns a `ChangePreview` and
  `cancel()` defaults to the end of the period, as the methods have since 0.4.0.

### Upgrade notes

- **Why a new contract.** Adding methods to `ManagementTransport` would break every
  transport written outside this package. The feed and reactivate live on
  `SubscriptionTransport` instead. If you wrote your own management transport, it keeps
  working; implement `SubscriptionTransport` too when you want the new calls. Until then
  `reactivate()`, `subscriptions()` and `allSubscriptions()` throw `UnsupportedOperation`
  and send nothing. The two contracts may merge in the next minor release.
- The recorded server fixtures in `tests/Fixtures/server` now carry `organization_ref`,
  `pause_reason` and `discount`. The contract suite expects a server that sends them.

## 0.4.1

A patch release. No public signature changes, but the default outage behaviour changes:
read the upgrade notes.

### Fixed

- **An outage no longer over-admits or blocks too early.** While billing was unreachable
  the client either admitted every request (`allow`) or refused as soon as the local lease
  was spent (`deny`). A free organization with a 1,000,000-span block meter, 300,000 used
  and a 100,000 lease unspent, ended an outage at 1,200,000 under `allow` and was cut off
  at 400,000 under `deny`. The new default policy stops it at 1,000,000.

### Added

- `FailurePolicy::LastKnown` (`fail = last_known`), now the default. During an outage the
  client admits up to the last known allowance minus what billing has leased to it, then
  refuses with `QuotaExceeded` on a hard-limit meter. `bill`-overage and unlimited meters
  keep admitting. With no known entitlement it refuses as `deny` does. A refusal emits the
  `denied` signal with reason `outage_limit`. See `docs/core-concepts/failure-policy.md`.
- `Contracts\AllowanceSnapshots` and `Stores\CacheAllowanceSnapshots`, bound by the
  provider, and the `snapshot_refresh` setting (`BILLING_CLIENT_SNAPSHOT_REFRESH`,
  default 300 seconds).
- `Entitlement::isCapped()`, `FailurePolicy::enforcesLastKnown()` and
  `InteractsWithBillingClient::allowanceSnapshots()`.
- An optional trailing `snapshots` constructor argument on `BillingClient` and
  `LeaseManager`.

### Upgrade notes

- **The default policy is `last_known`.** Under it the client reads
  `GET /api/v1/entitlements/{org}` on a lease refill, at most once per `snapshot_refresh`
  seconds. To keep the 0.4.0 behaviour, set `BILLING_CLIENT_FAIL=allow`. `allow` and
  `deny` are unchanged.
- An unrecognised `fail` value now falls back to `last_known` instead of `allow`.
- A `BillingClient` built by hand, and `makeBillingClient()` in tests, still default to
  `allow`. Pass `FailurePolicy::LastKnown` (and, by hand, an `AllowanceSnapshots`) to use
  the new policy there.

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
