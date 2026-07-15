---
title: Show usage
weight: 32
description: Render the current period's per-meter usage and the invoice history for a self-service billing screen.
---

# Show usage

A self-service billing screen shows a user where they stand: how much of each meter's
allowance they have consumed this period, and their past invoices. Both come from the
[management client](../core-concepts/management-client.md).

## Current-period usage per meter

```php
use Cbox\Billing\Client\Facades\BillingManager;

$usage = BillingManager::usage($org); // UsageSummary

foreach ($usage->meters as $meter => $meterUsage) {
    // $meterUsage->used, $meterUsage->allowance, $meterUsage->overage
    $remaining = $meterUsage->remaining(); // max(0, allowance - used)
    $pct = $meterUsage->allowance > 0
        ? min(100, (int) round($meterUsage->used / $meterUsage->allowance * 100))
        : 0;
}

// The window the figures cover:
$usage->period->start;  // ?DateTimeImmutable
$usage->period->end;    // ?DateTimeImmutable
```

`UsageSummary` is deny-by-default: an unknown meter has no entry, so `for()` returns
`null` rather than a fabricated zero.

```php
$calls = $usage->for('api.calls');
if ($calls !== null && $calls->overage > 0) {
    // Warn the user they are into paid (or blocked) territory.
}
```

## Weighted cost

To turn raw usage into a billable figure, apply the meter's entitlement **weight**
(`raw × weight`). The enforcement client can read it for you, or compute it purely from
an `Entitlement` you already hold:

```php
use Cbox\Billing\Client\Facades\Billing;

// Convenience — reads the org's entitlements over the network:
$cost = Billing::cost($org, 'tokens', 2_000);   // 2_000 × weight

// Pure — no network, from an Entitlement/Entitlements you already have:
$entitlements = Billing::entitlements($org);
$cost = $entitlements->cost('tokens', 2_000);
```

Prefer the pure form in a hot loop and cache the `Entitlements`.

## Invoice history

```php
$invoices = BillingManager::invoices($org); // list<Invoice>

foreach ($invoices as $invoice) {
    // $invoice->number, $invoice->date (?DateTimeImmutable),
    // $invoice->amountMinor, $invoice->currency, $invoice->status
    $paid = $invoice->isPaid();
}
```

Amounts are integer **minor units** — format for display at the edge, never as floats.
