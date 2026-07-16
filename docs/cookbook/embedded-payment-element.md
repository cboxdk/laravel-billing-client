---
title: Embedded payment element
weight: 34
description: Collect payment in-page — create a setup or payment intent, mount the gateway element client-side, handle SCA, and confirm settlement by webhook (ADR-0009 path B).
---

# Embedded payment element

When you want checkout to happen **inside your own UI** rather than on a redirect, use the
embedded flow — **path B** of the payment design. The SDK creates an **intent** on the
billing service and hands your front-end exactly what it needs to mount the gateway's own
element:

```text
{ gateway, publishableKey, clientSecret }
```

- `gateway` — which gateway the service is configured to use, so the front-end loads the
  matching JavaScript.
- `publishableKey` — the gateway's client-side key for that account.
- `clientSecret` — the per-intent secret the element confirms against.

> **The gateway JavaScript is the product's responsibility.** This SDK is deliberately
> gateway-agnostic: it returns the three fields above and never bundles a gateway SDK,
> renders an element, or processes card data server-side. Card details go straight from the
> browser to the gateway; they never touch your Laravel app. What you mount, and how, is
> your front-end's job.

## Store a card without charging: setup intent

To save a payment method for later (trials, usage billing, "add a card"), create a
**setup intent**:

```php
use Cbox\Billing\Client\Facades\BillingManager;

public function setupIntent(string $org)
{
    $intent = BillingManager::createSetupIntent($org);

    return response()->json([
        'gateway'         => $intent->gateway,
        'publishable_key' => $intent->publishableKey,
        'client_secret'   => $intent->clientSecret,
    ]);
}
```

The front-end loads the gateway JS for `gateway`, initialises it with `publishable_key`,
mounts the element, and confirms it against `client_secret`.

## Charge now: payment intent

To take a payment immediately — settle an open invoice or an ad-hoc amount — create a
**payment intent**. Pass **either** a `reference` (something the service already knows how
to price, e.g. an invoice id) **or** an `amountMinor` (integer minor units) with a
`currency`:

```php
// Settle a known invoice:
$intent = BillingManager::createPaymentIntent($org, reference: 'inv_123');

// Or charge an ad-hoc amount (minor units — cents):
$intent = BillingManager::createPaymentIntent($org, amountMinor: 4_900, currency: 'usd');
```

Passing neither is a programming error and throws `InvalidArgumentException` before any
request is made.

## Handling SCA (strong customer authentication)

An intent can come back needing an extra step — a bank challenge, a 3-D Secure prompt. The
value object surfaces this as a status:

```php
if ($intent->requiresAction()) {
    // status === 'requires_action' — the gateway element must run its challenge flow
    // in the browser before the intent settles. Your front-end drives that with the
    // gateway JS and the client_secret; there is nothing more for the server to do here.
}
```

The server does **not** poll for the outcome. The gateway element completes the challenge
client-side, and the billing service tells you the final result out-of-band.

## Confirmation is a webhook, not a response

`createPaymentIntent()` returning does **not** mean the money moved — it means an intent
exists. **Settlement is confirmed by a webhook** the billing service sends once the charge
(and any SCA) completes. Fulfil the order, mark the invoice paid, or grant access on that
webhook, never on the intent-creation response. The intent's `reference` is what ties the
webhook back to the intent you created.

## Manage stored payment methods

Once cards are on file, list them, change the default, and remove them:

```php
$methods = BillingManager::paymentMethods($org);   // list<PaymentMethod>

foreach ($methods as $method) {
    // $method->id, $method->brand, $method->last4,
    // $method->expMonth, $method->expYear, $method->isDefault
}

BillingManager::setDefaultPaymentMethod($org, 'pm_2');
BillingManager::removePaymentMethod($org, 'pm_1');
```

`PaymentMethod` carries only the gateway-safe descriptor — brand, last four, expiry — never
a full number or CVC. A re-default or removal of an unknown id is refused with a
`TransportException`, as is any outage.

## Testing offline

The `FakeManagementTransport` reproduces the service shapes — intents come back with a
`requires_action` status so the SCA branch is exercised, and payment methods list,
re-default, and remove with the same deny-by-default 404 on an unknown id:

```php
use Cbox\Billing\Client\ValueObjects\PaymentMethod;

// in a test using InteractsWithBillingClient:
$this->managementTransport()
    ->withPaymentMethod('org_42', new PaymentMethod('pm_1', 'visa', '4242', 12, 2030, isDefault: true))
    ->withPaymentMethod('org_42', new PaymentMethod('pm_2', 'mastercard', '4444', 1, 2031, isDefault: false));

$management = $this->makeBillingManagement();

$intent = $management->createSetupIntent('org_42');
expect($intent->requiresAction())->toBeTrue();

$management->setDefaultPaymentMethod('org_42', 'pm_2');
$management->removePaymentMethod('org_42', 'pm_1');

expect($management->paymentMethods('org_42'))->toHaveCount(1);
```
