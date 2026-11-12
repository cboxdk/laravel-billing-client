---
title: Hosted checkout & portal
weight: 33
description: Collect payment the low-effort way — redirect the user to a billing-hosted checkout or self-service portal session (ADR-0009 path A).
---

# Hosted checkout & portal

The lowest-effort way to take payment: let the billing service host the payment pages.
The SDK asks for a **session**, you redirect the user to its `url`, and billing handles
card entry, strong customer authentication (SCA), and receipts on its own domain. This is
**path A** of the payment design — no gateway JavaScript in your app, no card data near
your servers.

Use it when you want to be live quickly, or when regulatory scope reduction matters more
than a fully in-app checkout. For an embedded, in-page element instead, see
[the payment element recipe](embedded-payment-element.md).

## Redirect to checkout

`createCheckoutSession()` returns a short-lived `CheckoutSession{url, expiresAt}`. Mint one
per attempt and redirect straight to it — never cache the URL, it expires.

```php
use Cbox\Billing\Client\Facades\BillingManager;

public function checkout(string $org)
{
    $session = BillingManager::createCheckoutSession(
        org: $org,
        plan: 'pro',
        returnUrl: route('billing.done'),   // where billing returns the user afterwards
        currency: 'usd',                    // optional — omit to use the plan's currency
    );

    return redirect()->away($session->url);
}
```

`expiresAt` is a `DateTimeImmutable` (or `null` if the service does not scope it). The value
object can tell you whether a link you are holding has gone stale:

```php
if ($session->isExpired()) {
    // Mint a fresh session rather than redirecting to a dead link.
}
```

## Apply a promo code

Pass the code the customer typed as `coupon` (since 0.6.3). Billing checks it against the plan,
the currency and the organization before it mints the session, so the URL only exists for a code
that will discount; the code is redeemed when the checkout completes. A code it will not apply
(unknown, expired, used up, not valid for the plan or the currency) is a `BillingRefused` with
status 422, and its `body` carries the reason to show the customer.

```php
use Cbox\Billing\Client\Exceptions\BillingRefused;

try {
    $session = BillingManager::createCheckoutSession($org, 'pro', route('billing.done'), coupon: $request->input('coupon'));
} catch (BillingRefused $e) {
    if ($e->status === 422) {
        return back()->withErrors(['coupon' => 'That code does not apply to this plan.']);
    }

    throw $e;
}
```

A blank code counts as none, so an empty form field can be passed straight through. A code that
takes the whole price off completes on the hosted page without a card. Passing a coupon needs a
transport that implements `Contracts\CouponCheckoutTransport`, as the HTTP transport and the fake
do; on one that does not, `BillingManagement` throws `UnsupportedOperation` and sends nothing. A
checkout without a coupon works on any transport.

## After checkout

The user completes payment on the billing-hosted page and is sent back to `returnUrl`.
**Settlement is confirmed by a webhook**, not by the return redirect — treat the return as
"the user came back", and the webhook as "the money moved".

## Manage billing in the hosted portal

For an existing customer who wants to update a card, download invoices, or manage their
plan, open a **portal session** and redirect to it:

```php
$portal = BillingManager::createPortalSession($org, returnUrl: route('billing.account'));

return redirect()->away($portal->url);
```

`PortalSession` carries just the `url`. As with checkout, mint one per visit.

### Open the portal at the right page

Since 0.6.1, `section` opens a page or a Settings section instead of the overview, so an
"Update your card" or "Change billing details" link lands on that form in the portal rather
than on a form your app keeps:

```php
use Cbox\Billing\Client\Enums\PortalSection;

$portal = BillingManager::createPortalSession(
    $org,
    returnUrl: route('billing.account'),
    section: PortalSection::PaymentMethods,
);
```

| `PortalSection` | Opens |
| --- | --- |
| `Overview` (the default) | the portal's overview |
| `Subscriptions`, `Invoices`, `Settings` | that page |
| `PaymentMethods` | Settings, at the payment methods |
| `BillingDetails` | Settings, at the billing details |
| `PendingPayment` | the pay page of one invoice (below) |

The string values (`'payment_methods'`, …) are accepted too; an unknown one throws an
`InvalidArgumentException` before anything is sent. A call without `section` sends exactly
what 0.6.0 sent. The server needs to know `section` (Billing from 2026-11-12); an older one
opens the overview.

### Take a payment that waits on the customer

When a plan's first charge needs 3-D Secure, or a card was declined, send the customer to the
pay page of that invoice. Name the invoice (`inv_…` or its number), or the subscription
(`sub_…`) and Billing picks its newest unpaid invoice:

```php
$portal = BillingManager::createPortalSession(
    $org,
    returnUrl: route('billing.account'),
    section: PortalSection::PendingPayment,
    subscription: $subscriptionId,
);

return redirect()->away($portal->url);
```

The page runs the challenge, and once the invoice is paid sends the customer back to
`returnUrl` with `billing_payment=paid` and `billing_invoice={number}` added. Its back link
carries `billing_payment=open`. Treat the parameter as a hint for the message you show, and
read the invoice (or wait for the webhook) for the final word:

```php
if ($request->query('billing_payment') === 'paid') {
    $invoice = BillingManager::invoice($org, (string) $request->query('billing_invoice'));
}
```

`PendingPayment` without an `invoice` or `subscription`, or either of them with another
section, throws an `InvalidArgumentException` before anything is sent. Billing answers 404
for an invoice or subscription that is not the organization's, and 409 when the subscription
owes nothing; both arrive as a `BillingRefused`.

Sections need a transport that implements `PortalSectionTransport`. `HttpManagementTransport`
and `FakeManagementTransport` do; a transport of your own written against 0.6.0 keeps working
for calls without a section and throws `UnsupportedOperation` for one with it.

## Deny-by-default

Both calls throw a `TransportException` if the management API is unreachable
(`BillingUnavailable`), refuses the call (`BillingRefused`), or sends a malformed body — the SDK never fabricates a session URL. An unknown
plan on checkout is refused the same way. Let the exception surface; do not redirect to a
guessed URL.

## Testing offline

The `FakeManagementTransport` mints real-shaped sessions with an expiry, so the whole flow
is testable without a network. `expireSessions()` forces the next session to come back
already expired so you can drive `isExpired()`:

```php
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Plan;

// in a test using InteractsWithBillingClient:
$this->managementTransport()->withPlan(
    new Plan('pro', 'Pro', 4_900, 'usd', 'month', [
        new Entitlement('api.calls', enabled: true, allowance: 100_000, weight: 1.0, overage: 'bill'),
    ]),
);

$session = $this->makeBillingManagement()->createCheckoutSession('org_42', 'pro', 'https://app.test/done');

expect($session->isExpired())->toBeFalse();

$this->managementTransport()->expireSessions();
$stale = $this->makeBillingManagement()->createCheckoutSession('org_42', 'pro', 'https://app.test/done');
expect($stale->isExpired())->toBeTrue();
```

A coupon has to be seeded with `withCoupon($code, ...$plans)` (no plans named: every plan). The
fake answers an unknown code, or one not valid for the plan, with a 422, as Billing does, and
records every plan checkout with its coupon (upper-cased, as Billing stores it):

```php
$this->managementTransport()->withCoupon('LAUNCH100');

$this->makeBillingManagement()->createCheckoutSession('org_42', 'pro', 'https://app.test/done', coupon: 'launch100');

expect($this->managementTransport()->checkoutSessions()[0]['coupon'])->toBe('LAUNCH100');
```
