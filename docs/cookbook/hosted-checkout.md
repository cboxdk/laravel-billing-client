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

## Deny-by-default

Both calls throw `TransportException` if the management API is unreachable, returns a
non-2xx, or sends a malformed body — the SDK never fabricates a session URL. An unknown
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
