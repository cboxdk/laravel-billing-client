---
title: Management client
weight: 27
description: The typed self-service seam a product app calls to let its users browse plans, subscribe, change or cancel, and read usage and invoices.
---

# Management client

The enforcement `BillingClient` is the hot path — reserve, commit, meter. The
**`BillingManagement`** client is the other half: the self-service surface a product app
calls to let **its** users manage their own billing. It is a thin, typed seam over the
management API that returns immutable value objects and is deny-by-default about
outages.

```php
use Cbox\Billing\Client\Facades\BillingManager;

// Provision the org you bill FIRST — subscribe/checkout on an unknown org 404s.
// Idempotent, so it's safe to call before every flow. `billing_currency` is applied
// only on create (the service's one-way currency lock).
BillingManager::ensureOrganization('org_42', [
    'name' => 'Acme Inc',
    'billing_email' => 'billing@acme.test',
    'billing_currency' => 'USD',
]);

$plans = BillingManager::plans('USD');                  // list<Plan>, priced in USD
$current = BillingManager::subscription('org_42');      // ?Subscription
$result = BillingManager::subscribe('org_42', 'pro');   // SubscriptionResult (payment intent: null today)
$preview = BillingManager::previewChange('org_42', 'scale');
$updated = BillingManager::changePlan('org_42', 'scale');
$canceled = BillingManager::cancel('org_42', atPeriodEnd: true);
$back = BillingManager::reactivate('org_42');           // undo the cancel (0.4.2)
$page = BillingManager::subscriptions(updatedSince: $lastSync); // change feed (0.4.2)
$usage = BillingManager::usage('org_42');               // UsageSummary
$invoices = BillingManager::invoices('org_42');         // list<Invoice>
```

## Provision the organization first

`subscribe`, `changePlan`, and checkout all act on an organization that must already
exist on the billing service — calling them for an unknown org throws a
`BillingRefused` (404 "Unknown organization"). `ensureOrganization($org, $attributes)`
is an idempotent upsert (`PUT /v1/organizations/{org}`); call it once before the
first billing action for a customer. It's the first step in every cookbook below.

To show what billing stores (an app's own billing page), use `upsertOrganization($org,
$attributes)`, the same call with billing's answer: an `OrganizationProfile` with the billing
email, address, `taxId` and `taxIdValidated`, and `shared` when another product owns the account
(the contact and address then read null). Send `tax_id` to set the VAT number; billing checks it
against the register, and `taxIdSupported` is false on a server that does not take it.

To only show what is on file, use `organizationProfile($org)` (0.6.3): a read-only
`GET /v1/organizations/{org}` that answers the same `OrganizationProfile` as the customer's own
view (name, billing email and phone, the address with its ISO country and subdivision,
`taxId` with `taxIdValidated`, `purchaserType`, `eanNumber`, `orderReference` and `currency`).
It never creates the organization: an unknown one throws a `BillingRefused` with status 404. The
token needs `organizations:read`, which `organizations:write` does not include. Cbox apps keep no
billing-details form of their own; they show these fields as text next to a link to the portal:

```php
$profile = BillingManager::organizationProfile($org);
$manage = BillingManager::createPortalSession($org, url()->current(), section: PortalSection::BillingDetails)->url;
```

Three optional attributes describe who the customer buys as and what its invoices carry
(0.4.3, additive):

```php
BillingManager::ensureOrganization('org_region', [
    'name' => 'Region Hovedstaden',
    'billing_country' => 'DK',
    'purchaser_type' => 'public_body',      // the tax engine's PurchaserType values
    'ean_number' => '5790000000005',        // 13-digit GLN, GS1 check digit validated
    'order_reference' => 'Att. Jens Hansen', // printed on every invoice
]);
```

`purchaser_type` asks the place's own tax rule and never exempts by itself: most rules
need a verified exemption certificate on file. A Danish public body (`public_body` in
`DK`) needs a valid `ean_number` before Billing issues its invoices; until then they are
held.

## Multi-product instances & currency

On a shared billing instance serving several products, your API token is **bound to one
product**: `plans()` returns only your product's catalog, and subscribe/checkout refuse
another product's plan keys. Pass a currency to `plans('USD')` (and
`createCheckoutSession(..., currency: 'USD')`) to price the catalog — a plan not priced
in that currency is omitted rather than shown at a fabricated rate.

## Plan features and caching the catalog

Billing is the source of truth for your plans: prices, metered entitlements and features. Each
`Plan` carries its `features` map, the key of its `product`, and `taxInclusive`, so you can
cache the catalog instead of hard-coding limits:

```php
$plan = collect(BillingManager::plans('DKK'))->firstWhere('key', 'sites-standard');

$plan->featureInt('sites.pages');      // 15: an integer limit (0 when not granted)
$plan->hasFeature('sites.forms');      // true: granted
$plan->feature('sites.whitelabel');    // false: not granted
$plan->feature('sites.unknown');       // null: the server does not list it
$plan->taxInclusive;                   // false: the price is net, tax is added at invoicing
```

The server lists every feature any plan of your product grants, deny-by-default: `false` when
the plan does not grant it, `true` for a granted boolean feature, the `int` or `string` value of a
granted config feature. `0` is a granted limit of zero. A server older than features sends none,
and `features` is an empty array.

`taxInclusive: false` means the amount excludes VAT. `null` means the server did not say.

## Prices with tax

Billing works out the tax on every price it shows, through Cbox Tax, the same way the customer's
invoice is taxed. Show what the customer pays instead of adding a rate yourself:

```php
// For a billing account: its country, business or consumer status and a VAT number billing
// validated (VIES) decide the tax. For a visitor: a consumer in that country. Neither: a consumer
// in the seller's own country (Denmark, 25 % moms).
$plans = BillingManager::plans('DKK', org: $billingOrg);
$plans = BillingManager::plans('DKK', country: 'SE');
$plans = BillingManager::plans('DKK');

$plan->grossMinor();          // 4875: what the buyer pays, tax included; null when not known
$plan->tax->status;           // TaxStatus::Calculated, Pending or Unavailable
$plan->tax->basis;            // TaxBasis::Customer, Country or SellerDefault
$plan->tax->treatment;        // 'standard', 'reverse_charge', 'not_registered', …
$plan->tax->rate;             // '25'
$plan->tax->isReverseCharge(); // an EU business with a validated VAT number: no VAT charged
```

Only a `Calculated` answer carries amounts. `Pending` (the place cannot be taxed from what billing
knows) and `Unavailable` (Cbox Tax did not answer) leave the price net: say that the price excludes
VAT rather than showing it as final. A Billing server that predates tax on prices sends no `tax`,
and `$plan->tax` is `null`.

The subscription read carries the same for what the subscription costs per period
(`$subscription->recurring`, a `RecurringPrice`: `netMinor` after the bundle benefit and the
coupon, `tax`, and `totalMinor()`), a change preview splits what is due now
(`dueNowMinor` includes tax, `dueNowNetMinor`, `dueNowTaxMinor`, `dueNowTax`) and taxes the new
price (`newRecurringTax`), and an invoice has `subtotalMinor` and `taxMinor` beside its total
`amountMinor`. Since 0.6.3 a listed `Invoice` also carries `id`, Billing's public id (`inv_…`):
the stable handle to link or store, where `number` is what the customer reads. It is null from a
server that does not send it.

Naming a buyer needs a transport that implements `Contracts\TaxedPlanTransport`, as the HTTP
transport and the fake do; `plans()` throws `UnsupportedOperation` otherwise. The tax depends on the
buyer, so cache a per-organization answer briefly, if at all.

Billing sends a signed `plan.changed` webhook, one per changed plan, when a plan's prices,
entitlements, features, name, interval, product or active state change. A catalog apply that
changes nothing sends none. Subscribe an endpoint to it, filtered to your product, and refresh
your cache from `plans()` when it arrives. This package does not receive webhooks; verify and
route them in your app. The payload and signing are in Cbox Billing's webhook docs.

## The transport seam

Like the enforcement path, the network lives behind one contract — `ManagementTransport`
— with a real HTTP implementation and an in-memory fake:

| Method | Endpoint |
| --- | --- |
| `plans()` | `GET /v1/plans` |
| `organizationProfile($org)` ⁴ | `GET /v1/organizations/{org}` |
| `subscription($org)` | `GET /v1/subscriptions/{org}` |
| `subscribe($org, $plan)` | `POST /v1/subscriptions` |
| `previewChange($org, $plan)` | `POST /v1/subscriptions/{org}/preview` |
| `changePlan($org, $plan)` | `POST /v1/subscriptions/{org}/change` |
| `cancel($org, $atPeriodEnd)` | `POST /v1/subscriptions/{org}/cancel` |
| `reactivate($org)` ¹ | `POST /v1/subscriptions/{org}/reactivate` |
| `subscriptions(?$updatedSince, ?$cursor, ?$limit)` ¹ | `GET /v1/subscriptions` |
| `usage($org)` | `GET /v1/usage/{org}` |
| `invoices($org)` | `GET /v1/invoices/{org}` |
| `createInvoice($org, $invoice, $idempotencyKey)` ² | `POST /v1/invoices/{org}` |
| `invoice($org, $number)` ² | `GET /v1/invoices/{org}/{number}` |
| `createCheckoutSession($org, $plan, $returnUrl, ?$currency, ?$idempotencyKey, ?$coupon)` ⁵ | `POST /v1/checkout-sessions` |
| `createPortalSession($org, $returnUrl, ?$idempotencyKey, ?$section, ?$invoice, ?$subscription)` ³ | `POST /v1/portal-sessions` |
| `createSetupIntent($org)` | `POST /v1/setup-intents` |
| `createPaymentIntent($org, ?$reference, ?$amountMinor, ?$currency)` | `POST /v1/payment-intents` |
| `paymentMethods($org)` | `GET /v1/payment-methods/{org}` |
| `setDefaultPaymentMethod($org, $id)` | `POST /v1/payment-methods/{org}/default` |
| `removePaymentMethod($org, $id)` | `DELETE /v1/payment-methods/{org}/{id}` |

¹ Added in 0.4.2 on a separate contract, `SubscriptionTransport`, so a transport you wrote
against 0.4.1 still compiles. `HttpManagementTransport` and `FakeManagementTransport`
implement both. Calling one of these through `BillingManagement` on a transport that only
implements `ManagementTransport` throws `UnsupportedOperation`; nothing is sent.

² Added in 0.4.7 on a separate contract, `InvoiceTransport`, with the same compatibility rule as
¹: a transport that does not implement it makes `BillingManagement` throw `UnsupportedOperation`.

³ `section`, `invoice` and `subscription` were added in 0.6.1 on a separate contract,
`PortalSectionTransport`: only a call that uses them needs it. See
[open the portal at the right page](../cookbook/hosted-checkout.md#open-the-portal-at-the-right-page).

⁴ Added in 0.6.3 on a separate contract, `OrganizationReadTransport`, with the same compatibility
rule as ¹.

⁵ `coupon` was added in 0.6.3 on a separate contract, `CouponCheckoutTransport`: only a call that
passes a code needs it. See [apply a promo code](../cookbook/hosted-checkout.md#apply-a-promo-code).

Every call authenticates with the configured bearer token. The transport is **bound only
when a base URL and token are configured** — otherwise the host binds its own (e.g. the
fake in tests), keeping the package deny-by-default rather than pointing at a phantom
endpoint.

## Create a manual invoice

Since 0.4.7 an app can issue an invoice to one of its organizations, the way the billing console's
"new invoice" does. The token needs the `invoices:write` scope (`invoices:read` to read one back).

```php
use Cbox\Billing\Client\Facades\BillingManager;
use Cbox\Billing\Client\ValueObjects\InvoiceAppendix;
use Cbox\Billing\Client\ValueObjects\NewInvoice;
use Cbox\Billing\Client\ValueObjects\NewInvoiceLine;

$issued = BillingManager::createInvoice('org_42', new NewInvoice(
    lines: [new NewInvoiceLine('Development, October', '12.500', 120_000, unit: 't')],
    orderReference: 'PO-4471',
    appendix: new InvoiceAppendix(
        'Specification',
        [['key' => 'date', 'label' => 'Date'], ['key' => 'hours', 'label' => 'Hours', 'align' => 'right']],
        [['20.10.2026', '1,00'], ['21.10.2026', '0,75']],
        ['I alt', '1,75'],
    ),
    metadata: ['work_invoice_id' => 'inv_01J'],
), idempotencyKey: 'inv_01J');

$issued->number;    // "INV-1001"
$issued->pdfUrl;    // signed, expires in 15 minutes
```

- `quantity` is a decimal string with at most three fractional digits; the client sends it as given.
  `unitAmountMinor` is a positive integer in the account's billing currency.
- The `$idempotencyKey` is required. Use your own record's id: a retry with the same key and body
  returns the same invoice, the same key with another body is a 409 `idempotency_conflict`.
  `IssuedInvoice::$id` is billing's public id, a string (`inv_…`).
- `metadata` is flat strings: at most 20 keys, each key at most 40 characters matching
  `[A-Za-z0-9_.-]`, each value at most 200 characters. It is returned on read and in the
  `invoice.issued` webhook.
- The appendix has 1 to 8 columns and 1 to 2000 rows; a cell is at most 500 characters, a column
  label at most 100, the title at most 120, and all text together at most 1,000,000 characters.
- Refusals are a `BillingRefused`; read `->status` and decode `->body`:
  - 400 `idempotency_key_required`; 403 `product_bound_token` (the token is bound to one product).
  - 422 `billing_address_required`, body `{error, message, missing}` with the missing fields.
  - Any other 422 is Laravel's validation shape, `{message, errors: {path: [...]}}`, with no
    `error` code: read `errors` for the field paths such as `lines.0.quantity`.
  - 409 `tax_pending`, `seller_incomplete`, `invoice_refused`, `idempotency_conflict` (same key,
    another body) and `idempotency_in_flight` (the first request is still running; retry).
- `invoice($org, $number)` returns the same `IssuedInvoice` later. A sandbox environment answers
  `environment: "sandbox"` and never reaches a gateway or an accounting system.

## Discounts, pauses and your organization key

Since 0.4.2 every `Subscription` carries:

- `discount`: the bound coupon as a `SubscriptionDiscount` (`code`, `name`, `percentOff` or
  `amountOffMinor` + `currency`, `duration` of `once`, `repeating` or `forever`, and
  `endsAt`), or null. `ChangePreview::$discount` shows the coupon on a preview or change.
- `organizationRef`: your own tenant key for the organization, the `{org}` you call with.
- `paused` and `pauseReason` (today only `billing_address`); `isPaused()` checks either.

An older server leaves these out. They then read as null and false; nothing throws.

## Bundles, trials and scheduled changes

Since 0.4.4 a `Subscription` carries `trialEndsAt`, a scheduled plan change (`pendingChange`, the
plan key and when) and the cross-product `bundle` benefit on it. A `SubscriptionChange` carries the
`reference` (a Sites website) and the `bundle` too. `bundles($org)` answers what an app shows about
bundles and `previewCancel($org)` what a cancel would cost the organization's other
subscriptions; both live on the `BundleTransport` contract, which the HTTP and fake transports
implement. See [Show bundles and cancel consequences](../cookbook/show-bundles.md).

## Reactivate

`reactivate($org)` resumes a paused subscription, undoes a scheduled period-end cancel, or
brings back one canceled within the win-back window, and returns the `Subscription`. On a
subscription that is already active the server answers 409 and you get a `BillingRefused`
with `status` 409; with no subscription at all it is a 404.

## Syncing subscriptions with the change feed

To mirror every subscriber's plan and status, read the change feed instead of calling
`subscription()` once per organization:

```php
use Cbox\Billing\Client\Facades\BillingManager;

$page = BillingManager::subscriptions(updatedSince: $lastSync, limit: 100); // SubscriptionPage

foreach ($page->data as $change) {                  // SubscriptionChange
    Tenant::where('key', $change->organizationRef)->update([
        'plan' => $change->plan,
        'billing_status' => $change->status,
    ]);
}

if ($page->hasMore) {
    $next = BillingManager::subscriptions(cursor: $page->nextCursor, limit: 100);
}
```

Rows come oldest change first. Keep the `updatedAt` of the last row you handled and pass it
as `updatedSince` next time. The filter is inclusive, so you may see that row again; make
your update idempotent.

`allSubscriptions(?$updatedSince, ?$pageSize)` reads every page for you, one request per page
as you iterate:

```php
foreach (BillingManager::allSubscriptions($lastSync) as $change) {
    // ...
}
```

Each `SubscriptionChange` has Billing's `id` (an int) and `organizationId` (Billing's own
organization id), and `organizationRef`, your tenant key. Key your records on
`organizationRef`. `organizationKey()` returns it, or Billing's id for an organization that
was provisioned without one. It also carries `plan` and `product` keys (null if the plan was
removed), `status`, `paused`, `pauseReason`, `seats`, the period bounds,
`cancelAtPeriodEnd`, `trialEndsAt`, `canceledAt`, `discount` and `updatedAt`.

## Collecting payment (ADR-0009)

Payment collection comes in two shapes, both behind the same seam:

- **Path A — hosted.** `createCheckoutSession()` and `createPortalSession()` return a
  short-lived `url` you redirect to; billing hosts the payment/portal pages, so no gateway
  code or card data goes near your app. See
  [hosted checkout & portal](../cookbook/hosted-checkout.md).
- **Path B — embedded.** `createSetupIntent()` (store a card, no charge) and
  `createPaymentIntent()` (charge now, by `reference` or `amountMinor`) return
  `{gateway, publishableKey, clientSecret}` so your front-end mounts the gateway's own
  element in-page and confirms it. `paymentMethods()` / `setDefaultPaymentMethod()` /
  `removePaymentMethod()` manage the stored cards. See
  [the embedded payment element](../cookbook/embedded-payment-element.md).

The SDK is **gateway-agnostic**: it relays the gateway identifier, publishable key, and
client secret, and never bundles a gateway SDK or touches card data. Confirmation of an
embedded charge arrives as a **webhook**, not the intent-creation response — an intent
returning means "an intent exists", not "the money moved". Any strong-customer-
authentication step is surfaced as `requiresAction()` on the intent and handled by the
gateway element client-side.

## Deny-by-default on outages

A management call that cannot complete **never fabricates a result**. A refusal (a 4xx
other than 408/429: a revoked token, an unknown plan, a validation error) throws
`BillingRefused`; a connection error, 5xx, 408, 429 or malformed body throws
`BillingUnavailable`. Both are `TransportException`s and carry the status, body and
`Retry-After` — see [Errors](errors.md). A caller can never mistake an unreachable service
for "no subscription" or "no invoices". The one read that legitimately has no answer, an
org with no subscription (a 404), returns `null`, distinct from an error.

## Typed value objects

Every response decodes into a `readonly` value object — `Plan`, `Subscription`,
`SubscriptionDiscount`, `SubscriptionPage`, `SubscriptionChange`, `SubscriptionResult`, `PaymentIntent`, `ChangePreview`, `PreviewLine`, `UsageSummary`,
`MeterUsage`, `BillingPeriod`, `Invoice`, and the payment types `CheckoutSession`,
`PortalSession`, `SetupIntent`, `PaymentMethod` — with amounts as integer **minor units**
(no float money) and dates parsed to `DateTimeImmutable`. See the cookbook for end-to-end
recipes: [subscribe, change & cancel](../cookbook/subscribe-change-cancel.md),
[show usage](../cookbook/show-usage.md), [hosted checkout & portal](../cookbook/hosted-checkout.md),
and [the embedded payment element](../cookbook/embedded-payment-element.md).

> The management API — plan catalogue, proration, invoicing — is implemented by the
> deployable Cbox Billing service. This package is the typed client that talks to it.


### Usage rates

`Plan::usagePrices` is an optional map of meter keys to `UsagePrice` values. Each
value has `minor`, `currency` and `per`, matching the public `GET /plans`
`usage_prices` contract. `minor` is the charge for each started block of `per`
units: a rate with `per: 1000` charges two blocks for 1,001 billable units. It is
not a fractional per-unit rate. Actual allowances and billable quantities remain
Billing's responsibility; this SDK does not calculate invoices from the map.

A `null` map means the server did not provide it. An empty map or missing meter
means no simple rate was supplied, not that usage is free. Billing omits tiered,
dimension-dependent and percentage rates from this map. A supplied zero minor
amount is preserved. Malformed rows, nonpositive block sizes and currencies that
differ from the resolved plan currency fail the catalog read.

These amounts follow the plan's `taxInclusive` convention. The plan's `tax` quote
applies to its recurring price, not to these usage blocks; do not reuse that tax
amount as a usage quote. The constructor field is optional and trailing, so old
servers and existing hand-built Plan objects continue to work.

### Grouped usage

For a meter configured with `group_by` in Billing, `MeterUsage::breakdown` carries
`UsageGroup` rows: the server's integer `used` quantity and its `dimensions` map.
A null dimension value means the source events did not identify that dimension;
it must not be assigned to an arbitrary project. `null` for the entire breakdown
means the server did not supply it, whereas an empty list is an explicitly empty
breakdown. Older servers and existing three-argument MeterUsage constructors work
unchanged. Malformed groups fail the read rather than silently dropping usage.

This is the current-period usage summary, not a historical event export or a
per-project monetary quote. Amounts and tax must still come from Billing. Sending
raw dimensioned usage events is a separate integration contract; reading these
rows does not activate event collection or billing.

### Raw usage events

`BillingManagement::ingestUsageEvents($org, $events)` sends 1–1,000 `UsageEvent`
objects through `POST /v1/events`. The optional `UsageEventTransport` contract
keeps existing custom transports compatible; a transport without it raises
`UnsupportedOperation`.

Persist the event ID, occurrence time, meter, integer quantity, service, dimensions
and optional series key before sending. Retry the same payload and ID. An event
supports ten string dimensions; keys use lowercase letters, digits and underscores.
A zero quantity is retained, including a terminating level for a time-weighted meter.
Never report the same consumption through both raw events and cumulative usage.

The returned `UsageEventReceipt` contains accepted and duplicate **counts**, plus
rejections with their batch index, event ID and reason. Rejections must be recorded
individually. The client verifies that every submitted event is accounted for and
that each rejection matches the submitted ID and index. A malformed receipt raises
`BillingUnavailable`: it does not establish delivery, even if the server may have
stored the events. Keep the batch pending for reconciliation or an identical retry.
The API does not say which individual non-rejected events were new or duplicates;
do not invent that distinction in an audit log.

An HTTP refusal remains `BillingRefused`; connection errors, throttling and server
errors remain `BillingUnavailable`. This method does not automatically retry, persist
an outbox, authorize a meter, or turn telemetry into chargeable usage. The consuming
application owns durable delivery and reconciliation. Billing's event dating and
ID-retention windows still apply; an indefinite replay guarantee is not provided.

For tests, seed `FakeManagementTransport::withUsageEventMeter($meter)` and inspect
`recordedUsageEvents($org)`. Unknown meters are rejected; an optional second argument
seeds another rejection reason. Replays deduplicate within that fake instance and
organization. The fake exercises the real HTTP response parser but does not simulate
retention expiry, time-window aggregation, invoice pricing or agreement eligibility.

Customer account profiles expose the saved billing phone as the nullable `billingPhone` property.

## Requestable resource limits

The optional `ResourceLimitTransport` adds `resourceLimits`,
`limitIncreaseRequests`, `requestLimitIncrease` and `cancelLimitIncrease` to
`BillingManagement`. An older custom transport throws `UnsupportedOperation`;
an older server's 404 remains a refusal. These calls do not purchase resources,
change prices or grant postpaid credit.

```php
$limits = $billing->resourceLimits($organizationId, 'hosting');
$sites = $limits['max_sites'] ?? null;
$ceiling = $sites?->ceilingAt(new DateTimeImmutable, maximumAgeSeconds: 30);
// null means unknown: refuse growth and obtain fresh authoritative limits.

$request = new \Cbox\Billing\Client\ValueObjects\LimitIncreaseInput(
    requestKey: $durableUuid,
    feature: 'max_sites',
    requestedLimit: 30,
    reason: 'We are adding more customer sites.',
);
$receipt = $billing->requestLimitIncrease($organizationId, $request, 'hosting');
```

Persist the UUID before sending and reuse it with exactly the same details after
a lost response. A receipt describes a request; only a fresh `resourceLimits`
answer establishes the effective ceiling. Approval and revocation belong to
Billing's operator API, not this customer-facing client. The requested value may
be partially approved, rejected, expired or revoked.

A ceiling is organization-wide for the selected product. It is not an inventory
reservation, recurring usage allowance, spend cap or unpaid-exposure allowance.
The host must serialize its resource admission and check each applicable bound.
`ceilingAt` returns null for future observations, excessive age or an expired
grant. It never falls back to a stale higher limit; zero remains a real limit.
The example's 30 seconds is a host freshness choice, not a server lease guarantee.

The shared `FakeManagementTransport::withResourceLimits` scripts typed ceilings
per organization/product. It supports pending request replay and cancellation,
pagination and the existing `takeDown` / `refuseWith` failure controls. It never
automatically approves requests or simulates a credit review.
