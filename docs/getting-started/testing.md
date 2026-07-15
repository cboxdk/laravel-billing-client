---
title: Testing
weight: 12
description: Drive the whole hot path offline with the InteractsWithBillingClient trait and the in-memory fake transport.
---

# Testing

The SDK is dogfooded through its own testing seam, and your app uses the same tools.
`Testing\InteractsWithBillingClient` wires a `BillingClient` against
`Testing\FakeBillingTransport` — an in-memory stand-in for the billing service — so the
entire two-tier flow runs offline and deterministically.

```php
use Cbox\Billing\Client\Testing\InteractsWithBillingClient;

uses(InteractsWithBillingClient::class);

it('enforces the hard limit at the leased ceiling', function () {
    $this->transport()->grant('org_a', 'api.calls', 250);

    $client = $this->makeBillingClient(leaseSize: 100);

    $reservation = $client->reserve('org_a', 'api.calls', 5);
    $client->commit($reservation, 5);

    expect($client->balance('org_a', 'api.calls'))->toBe(95);
});
```

## The fake

`FakeBillingTransport` mirrors the real service's invariants:

- **`grant($org, $meter, $allowance)`** — set the central allowance to lease from.
  Leases are pessimistic and can never over-grant.
- **`entitlement($org, $entitlement)`** — register what `reserveRemote` / `entitlements`
  return.
- **`takeDown()` / `bringUp()`** — simulate an unreachable billing service to exercise
  the fail-open / fail-closed policy.
- **`leasedOut()`, `reportedCumulative()`, `leaseCalls()`, `reportCalls()`** —
  assertions on what reached billing.

## Deterministic ids

`makeBillingClient()` injects a deterministic reservation-id factory, so buffered and
reported usage assert cleanly without stubbing randomness.

If a fake is ever awkward to use in your own suite, that is a signal to improve the
fake — not to reach around it.
