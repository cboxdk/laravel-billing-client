---
title: Subscribe, change & cancel
weight: 31
description: The plan lifecycle a product app exposes to its users — subscribe, preview a change, apply it, and cancel.
---

# Subscribe, change & cancel

These recipes drive the [management client](../core-concepts/management-client.md) to
build a self-service billing screen. Every method returns an immutable value object and
throws a `TransportException` (`BillingRefused` or `BillingUnavailable`, see
[Errors](../core-concepts/errors.md)) when it cannot answer, so a controller stays thin: resolve, delegate,
map.

## Show the plans and the current subscription

```php
use Cbox\Billing\Client\Facades\BillingManager;

$plans = BillingManager::plans();                  // list<Plan>
$current = BillingManager::subscription($org);      // ?Subscription (null if none)

foreach ($plans as $plan) {
    // $plan->key, $plan->name, $plan->priceMinor, $plan->currency, $plan->interval
    $isCurrent = $current?->plan === $plan->key;
}
```

Prices are integer **minor units** (e.g. cents) — format them at the edge, never as
floats. `priceMinor` is net; show `$plan->grossMinor()`, what the customer pays with tax, and pass
the customer's billing account (`plans(org: $org)`) so it is their tax. See
[Prices with tax](../core-concepts/management-client.md#prices-with-tax).

## Subscribe

A subscribe that is not a trial invoices the opening period and charges the saved card
off-session at once. Since 0.6.3 `$result->invoice` says how that went (a `ChargedInvoice`), and
`$result->subscription->id` is Billing's `sub_…` id:

```php
use Cbox\Billing\Client\Enums\InvoicePayment;
use Cbox\Billing\Client\Enums\PortalSection;

$result = BillingManager::subscribe($org, 'pro');
$invoice = $result->invoice;

if ($invoice?->payment === InvoicePayment::Pending) {
    // The charge needs the customer: 3-D Secure, a declined card, or a bank transfer.
    $portal = BillingManager::createPortalSession(
        $org,
        returnUrl: route('billing.done'),
        section: PortalSection::PendingPayment,
        invoice: $invoice->id,
    );

    return redirect()->away($portal->url);
}

// Paid, nothing due, or nothing billed yet (a trial): the subscription is serving.
return redirect()->route('billing.done');
```

| `$result->invoice` | Meaning |
| --- | --- |
| `payment === InvoicePayment::Paid` | The off-session charge went through. |
| `payment === InvoicePayment::NoneDue` | Nothing to collect: a free plan, a 100% promo code, account credit. |
| `payment === InvoicePayment::Pending` | Still owed. The customer completes 3-D Secure, replaces the card or pays on the portal's pay page for that invoice; Billing returns them to `returnUrl` with `billing_payment=paid\|open`. |
| `null` | Nothing was billed: a trial (billed when it converts), billing details still missing, or a server older than the field. |

A card that needs 3-D Secure off-session is declined by the gateway, so Billing also opens dunning
and emails the customer a link to authenticate; sending them to the pay page right away is just
faster. Use the invoice's `id`, never "the newest open invoice". `paymentIntent` is reserved and
always null from Billing, so `requiresPayment()` is false.

## Preview a change before applying it

Never surprise a user with a charge. `previewChange()` is a dry run that returns the
net due-now, the prorated credit, the new recurring amount, and itemized lines:

```php
$preview = BillingManager::previewChange($org, 'scale');

// $preview->dueNowMinor (tax included: $preview->dueNowNetMinor + $preview->dueNowTaxMinor),
// $preview->creditMinor, $preview->newRecurringMinor (net; with tax: $preview->newRecurringTax),
// $preview->effectiveAt, and $preview->lines (net, each: description + signed amountMinor)
foreach ($preview->lines as $line) {
    // $line->description, $line->amountMinor  (credits are negative)
}
```

When the user confirms, apply it:

```php
$change = BillingManager::changePlan($org, 'scale');
```

An upgrade applied now invoices the prorated amount and charges it off-session: `$change->invoice`
is a `ChargedInvoice` as on subscribe, so follow `InvoicePayment::Pending` the same way. It is
null for a scheduled change or when nothing is due now.

## Cancel

Let the subscription run to the end of the paid period (the default since 0.4.0, and the
server's own default), or end it now:

```php
BillingManager::cancel($org);                       // lapse at period end
BillingManager::cancel($org, atPeriodEnd: false);   // immediate

// A period-end cancellation stays `active` with no renewal scheduled:
$sub = BillingManager::subscription($org);
$sub?->cancelsAtPeriodEnd(); // true
```

## Reactivate

A user who changes their mind before the period ends can undo the cancel. `reactivate()`
also resumes a paused subscription and brings back one canceled within the win-back window:

```php
use Cbox\Billing\Client\Exceptions\BillingRefused;

try {
    $sub = BillingManager::reactivate($org);
    $sub->cancelsAtPeriodEnd(); // false
} catch (BillingRefused $e) {
    // 409: already active, nothing to undo. 404: no subscription at all.
}
```

## Show the discount

```php
$discount = BillingManager::subscription($org)?->discount;   // ?SubscriptionDiscount

if ($discount !== null) {
    $label = $discount->isPercentage()
        ? "{$discount->percentOff}% off"
        : "{$discount->amountOffMinor} {$discount->currency} off";   // minor units
    $until = $discount->endsAt?->format('Y-m-d');                     // null for forever
}
```

## Testing the flow offline

The `FakeManagementTransport` reproduces the service invariants — proration, an
unknown plan is refused, an unreachable API throws — so the whole lifecycle is testable
without a network:

```php
use Cbox\Billing\Client\Testing\InteractsWithBillingClient;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Plan;

// in a test using the trait:
$this->managementTransport()->withPlan(
    new Plan('pro', 'Pro', 4_900, 'usd', 'month', [
        new Entitlement('api.calls', enabled: true, allowance: 100_000, weight: 1.0, overage: 'bill'),
    ]),
);

$management = $this->makeBillingManagement();
$result = $management->subscribe('org_42', 'pro');

expect($result->invoice?->payment)->toBe(InvoicePayment::Paid);

// A charge stuck on 3-D Secure: the invoice is open, and the app sends the customer to pay it.
$this->managementTransport()->chargesPending();
$stuck = $management->subscribe('org_43', 'pro');

expect($stuck->invoice?->isPending())->toBeTrue();
```
