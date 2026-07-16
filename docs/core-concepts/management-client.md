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

$plans = BillingManager::plans();                       // list<Plan>
$current = BillingManager::subscription('org_42');      // ?Subscription
$result = BillingManager::subscribe('org_42', 'pro');   // SubscriptionResult (+ payment intent?)
$preview = BillingManager::previewChange('org_42', 'scale');
$updated = BillingManager::changePlan('org_42', 'scale');
$canceled = BillingManager::cancel('org_42', atPeriodEnd: true);
$usage = BillingManager::usage('org_42');               // UsageSummary
$invoices = BillingManager::invoices('org_42');         // list<Invoice>
```

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
| `usage($org)` | `GET /api/v1/usage/{org}` |
| `invoices($org)` | `GET /api/v1/invoices/{org}` |
| `createCheckoutSession($org, $plan, $returnUrl, ?$currency)` | `POST /api/v1/checkout-sessions` |
| `createPortalSession($org, $returnUrl)` | `POST /api/v1/portal-sessions` |
| `createSetupIntent($org)` | `POST /api/v1/setup-intents` |
| `createPaymentIntent($org, ?$reference, ?$amountMinor, ?$currency)` | `POST /api/v1/payment-intents` |
| `paymentMethods($org)` | `GET /api/v1/payment-methods/{org}` |
| `setDefaultPaymentMethod($org, $id)` | `POST /api/v1/payment-methods/{org}/default` |
| `removePaymentMethod($org, $id)` | `DELETE /api/v1/payment-methods/{org}/{id}` |

Every call authenticates with the configured bearer token. The transport is **bound only
when a base URL and token are configured** — otherwise the host binds its own (e.g. the
fake in tests), keeping the package deny-by-default rather than pointing at a phantom
endpoint.

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

A management call that cannot complete — connection error, non-2xx, or malformed body —
throws a `TransportException` and **never fabricates a result**. A caller can never
mistake an unreachable service for "no subscription" or "no invoices". Reads that
legitimately have no answer (an org with no subscription) return `null`, distinct from an
error.

## Typed value objects

Every response decodes into a `readonly` value object — `Plan`, `Subscription`,
`SubscriptionResult`, `PaymentIntent`, `ChangePreview`, `PreviewLine`, `UsageSummary`,
`MeterUsage`, `BillingPeriod`, `Invoice`, and the payment types `CheckoutSession`,
`PortalSession`, `SetupIntent`, `PaymentMethod` — with amounts as integer **minor units**
(no float money) and dates parsed to `DateTimeImmutable`. See the cookbook for end-to-end
recipes: [subscribe, change & cancel](../cookbook/subscribe-change-cancel.md),
[show usage](../cookbook/show-usage.md), [hosted checkout & portal](../cookbook/hosted-checkout.md),
and [the embedded payment element](../cookbook/embedded-payment-element.md).

> The management API — plan catalogue, proration, invoicing — is implemented by the
> deployable Cbox Billing service. This package is the typed client that talks to it.
