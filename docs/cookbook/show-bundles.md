---
title: Show bundles and cancel consequences
weight: 35
description: Show a cross-product bundle benefit next to the plan, offer what the organization could add to save, and warn before a cancel that ends a benefit elsewhere.
---

# Show bundles and cancel consequences

Cbox Billing can discount one product because the organization holds another: Cbox Tax is free
while it pays for Cbox Billing, or two paid products save 10%. The rules live in Billing's
catalog. Your app never hard-codes them: it shows what the server answers, in the customer's
language.

## The benefit on a subscription

Every `Subscription` (and every `SubscriptionChange` in the feed) carries `bundle`, null when no
rule discounts it.

```php
$subscription = $billing->subscription($org);
$benefit = $subscription?->bundle?->benefit;

if ($benefit !== null) {
    // "Included with Cbox Billing" · "100% off" · "DKK 0,00" (was "DKK 219,00")
    echo $benefit->label, ' · ', $benefit->text, ' · ', $benefit->priceAfter;

    if ($subscription->bundle->isEnding()) {
        echo ' until ', $subscription->bundle->endsAt?->format('j M Y');
    }
}
```

`bundle->next` is what applies from `bundle->nextFrom` (null: full price). A benefit earned after
the current period was invoiced reads `benefit` null and `next` from the next renewal.

## What the organization could add

```php
$bundles = $billing->bundles($org, locale: 'da');

foreach ($bundles->offers as $offer) {
    // $offer->headline: "Add Cbox Tax, free with your Billing plan"
    foreach ($offer->plans as $plan) {
        // $plan->priceBefore → $plan->priceAfter, link to $plan->checkoutUrl
    }
}
```

| `kind` | When | Show |
| --- | --- | --- |
| `add_product` | A product the organization does not hold is discounted now. | `headline`, each plan's price before and after, a button to `checkoutUrl`. |
| `unlock` | It holds the discounted product but not the one that earns the discount. | `headline`, the plans of `product`, and `saves` (what it would save on the subscription it holds). |
| `multi_product` | The next multi-product tier. | `headline` and `productsToAdd`. |

A product-bound token sees the bundles that involve its own product. A checkout that comes to
nothing (a product included in a bundle) completes without a payment.

## Before a cancel

Cancelling one product can end a benefit on another. Ask first, and show every consequence above
the cancel button:

```php
$preview = $billing->previewCancel($org);

foreach ($preview->consequences as $consequence) {
    // "“Included with Cbox Billing” no longer applies to Cbox Tax from 15 Nov 2026.
    //  From then Cbox Tax costs DKK 219.00 per month, excl. VAT."
    echo $consequence->message;
}
```

A plan-change preview carries the same list as `ChangePreview::$consequences`, for a downgrade that
ends a benefit.

## Testing

`FakeManagementTransport::withBundles($org, new OrganizationBundles(...))` seeds what `bundles()`
answers, and `withConsequences($org, ...)` what the cancel and change previews report.
