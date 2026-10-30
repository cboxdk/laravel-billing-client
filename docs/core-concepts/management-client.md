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
is an idempotent upsert (`PUT /api/v1/organizations/{org}`); call it once before the
first billing action for a customer. It's the first step in every cookbook below.

To show what billing stores (an app's own billing page), use `upsertOrganization($org,
$attributes)`, the same call with billing's answer: an `OrganizationProfile` with the billing
email, address, `taxId` and `taxIdValidated`, and `shared` when another product owns the account
(the contact and address then read null). Send `tax_id` to set the VAT number; billing checks it
against the register, and `taxIdSupported` is false on a server that does not take it.

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

`taxInclusive: false` means the amount excludes VAT. To show a consumer price including VAT,
apply the rate yourself (25 % for Danish moms). `null` means the server did not say.

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
| `plans()` | `GET /api/v1/plans` |
| `subscription($org)` | `GET /api/v1/subscriptions/{org}` |
| `subscribe($org, $plan)` | `POST /api/v1/subscriptions` |
| `previewChange($org, $plan)` | `POST /api/v1/subscriptions/{org}/preview` |
| `changePlan($org, $plan)` | `POST /api/v1/subscriptions/{org}/change` |
| `cancel($org, $atPeriodEnd)` | `POST /api/v1/subscriptions/{org}/cancel` |
| `reactivate($org)` ¹ | `POST /api/v1/subscriptions/{org}/reactivate` |
| `subscriptions(?$updatedSince, ?$cursor, ?$limit)` ¹ | `GET /api/v1/subscriptions` |
| `usage($org)` | `GET /api/v1/usage/{org}` |
| `invoices($org)` | `GET /api/v1/invoices/{org}` |
| `createCheckoutSession($org, $plan, $returnUrl, ?$currency)` | `POST /api/v1/checkout-sessions` |
| `createPortalSession($org, $returnUrl)` | `POST /api/v1/portal-sessions` |
| `createSetupIntent($org)` | `POST /api/v1/setup-intents` |
| `createPaymentIntent($org, ?$reference, ?$amountMinor, ?$currency)` | `POST /api/v1/payment-intents` |
| `paymentMethods($org)` | `GET /api/v1/payment-methods/{org}` |
| `setDefaultPaymentMethod($org, $id)` | `POST /api/v1/payment-methods/{org}/default` |
| `removePaymentMethod($org, $id)` | `DELETE /api/v1/payment-methods/{org}/{id}` |

¹ Added in 0.4.2 on a separate contract, `SubscriptionTransport`, so a transport you wrote
against 0.4.1 still compiles. `HttpManagementTransport` and `FakeManagementTransport`
implement both. Calling one of these through `BillingManagement` on a transport that only
implements `ManagementTransport` throws `UnsupportedOperation`; nothing is sent.

Every call authenticates with the configured bearer token. The transport is **bound only
when a base URL and token are configured** — otherwise the host binds its own (e.g. the
fake in tests), keeping the package deny-by-default rather than pointing at a phantom
endpoint.

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
