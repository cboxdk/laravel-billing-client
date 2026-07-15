---
title: Subscribe, change & cancel
weight: 31
description: The plan lifecycle a product app exposes to its users — subscribe, preview a change, apply it, and cancel.
---

# Subscribe, change & cancel

These recipes drive the [management client](../core-concepts/management-client.md) to
build a self-service billing screen. Every method returns an immutable value object and
throws `TransportException` on an outage, so a controller stays thin: resolve, delegate,
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
floats.

## Subscribe

```php
$result = BillingManager::subscribe($org, 'pro');

if ($result->requiresPayment()) {
    // Hand $result->paymentIntent->clientSecret to the front-end to confirm the charge.
    return response()->json([
        'client_secret' => $result->paymentIntent?->clientSecret,
    ]);
}

// Free/covered plan — the subscription is already active.
return response()->json(['status' => $result->subscription->status]);
```

## Preview a change before applying it

Never surprise a user with a charge. `previewChange()` is a dry run that returns the
net due-now, the prorated credit, the new recurring amount, and itemized lines:

```php
$preview = BillingManager::previewChange($org, 'scale');

// $preview->dueNowMinor, $preview->creditMinor, $preview->newRecurringMinor,
// $preview->effectiveAt, and $preview->lines (each: description + signed amountMinor)
foreach ($preview->lines as $line) {
    // $line->description, $line->amountMinor  (credits are negative)
}
```

When the user confirms, apply it:

```php
$subscription = BillingManager::changePlan($org, 'scale');
```

## Cancel

Cancel immediately, or let the subscription run to the end of the paid period:

```php
BillingManager::cancel($org);                       // immediate
BillingManager::cancel($org, atPeriodEnd: true);    // lapse at period end

// A period-end cancellation stays `active` with no renewal scheduled:
$sub = BillingManager::subscription($org);
$sub?->cancelsAtPeriodEnd(); // true
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

expect($result->requiresPayment())->toBeTrue();
```
