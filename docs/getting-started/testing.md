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

## The fakes

`FakeBillingTransport` and `FakeManagementTransport` are in-memory **servers** behind the
real HTTP transports. Each call goes over a faked HTTP client, the fake answers with the
JSON and status the Cbox Billing controllers send, and the real transport parses it. Your
tests therefore exercise the same parsing and the same error classification as
production, and a fake cannot hand back a shape the server never sends.

`FakeBillingTransport` mirrors the real service's invariants:

- **`grant($org, $meter, $allowance)`** — set the central allowance to lease from.
  Leases are pessimistic and can never over-grant.
- **`entitlement($org, $entitlement)`** — register what `reserveRemote` / `entitlements`
  return. A `FailurePolicy::LastKnown` client reads it on its first refill and enforces
  it during an outage; without it, that client refuses as `deny` once the lease is spent.
- **`takeDown()` / `bringUp()`** — simulate an unreachable billing service (a connection
  error, `BillingUnavailable`) to exercise the failure policy.
- **`refuseWith($status)`** — answer every call with a status: `401` for a revoked token
  (`BillingRefused`, which fails closed under every policy), `429` or `503` for load
  shedding (`BillingUnavailable`).
- **`leasedOut()`, `reportedCumulative()`, `leaseCalls()`, `reportCalls()`** —
  assertions on what reached billing.

`FakeManagementTransport` answers as the management API does: an organization with no
subscription reads as `null`, an unknown plan is a 404, a subscribe returns
`payment_intent: null`, and cancel defaults to period end. Reactivate undoes a period-end
cancel or a pause and is a 409 on an active subscription. The change feed lists every
subscription the fake has seen, pages by cursor, and reports `organization_ref` as the org
you called with; pass a third argument to `withSubscription()` to set when a row last
changed. It has `takeDown()` and
`refuseWith()` too, and `requireProvisionedOrganizations()` to refuse a subscribe for an
organization you never provisioned.

Manual invoices (0.4.7): `createInvoice()` through the fake numbers invoices `INV-1`, `INV-2`, and
remembers each `Idempotency-Key`, so replaying a key with the same body returns the same invoice
and replaying it with a different body is a 409 `idempotency_conflict`, as on the server. Seed an
invoice `invoice($org, $number)` should read back with `withIssuedInvoice($org, new IssuedInvoice(...))`;
an unknown number is a 404.

Every invoice `invoices($org)` lists has an `id` (0.6.3), as on the server: the one you seeded on
the `Invoice`, or a stable `inv_…` stand-in derived from the organization and number.

Subscribe and an immediate plan change answer the invoice they charged (0.6.3): `paid`, or
`none_due` for a zero amount. `chargesPending()` leaves every such charge `pending`, as when the bank
asks for 3-D Secure, so you can test sending the customer to the portal's pending-payment page; the
invoice is listed by `invoices()` with the same `id`. Every subscription the fake answers carries an
`id` (`sub_…`).

Checkout promo codes (0.6.3) are seeded with `withCoupon($code, ...$plans)`; an unknown code, one
not valid for the plan, or one on one-off products alone is a 422. `checkoutSessions()` lists every
plan checkout the fake opened with its org, plan, currency and coupon.

Billing's tax answers are seeded with `withTax()`: a `PriceTax` for nobody in particular, an
organization (`org:`) or a country (`country:`). The fake resolves the buyer as billing does (the
organization's answer, else the country's, else nobody's) and works out each amount from the
seeded rate. Without a seeded answer it sends no `tax`, like an older server. `taxUnavailable()`
answers `unavailable`, as billing does while Cbox Tax is down.

```php
$fake = (new FakeManagementTransport)
    ->withPlan(new Plan('standard', 'Standard', 3_900, 'DKK', 'month', []))
    ->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::SellerDefault, 'DK', 'consumer', 'standard', '25'))
    ->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::Customer, 'DE', 'business', 'reverse_charge'), org: 'org_de');
```

## Testing an outage

`makeBillingClient()` keeps `FailurePolicy::Allow` as its default, so existing suites
behave as before. To test the production default, pass `FailurePolicy::LastKnown` and
register the entitlement it should enforce:

```php
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\ValueObjects\Entitlement;

it('stops at the allowance while billing is down', function () {
    $this->transport()
        ->grant('org_a', 'api.calls', 1_000)
        ->entitlement('org_a', new Entitlement('api.calls', true, 1_000, 1.0, 'block'));

    $client = $this->makeBillingClient(leaseSize: 100, failurePolicy: FailurePolicy::LastKnown);
    $client->commit($client->reserve('org_a', 'api.calls', 100), 100);

    $this->transport()->takeDown();

    // 200 leased (the slice was topped up), so 800 can be admitted past the lease.
    $client->commit($client->reserve('org_a', 'api.calls', 800), 800);

    expect(fn () => $client->reserve('org_a', 'api.calls', 101))->toThrow(QuotaExceeded::class);
});
```

`$this->allowanceSnapshots()` returns the store that client enforces from.

## Testing production's refill and grace (0.5.0)

`makeBillingClient()` refills inline by default. Build it as production wires it:

- `queuedRefills: true` queues a low lease's top-up instead of making it. `queuedRefills()`
  lists what was queued, and `runQueuedRefills()` runs it as the `RefillLease` job would.
- `breakerCooldown: 30` opens the refill breaker after Billing was found unreachable.
- `graceUnits: 100` (with `FailurePolicy::LastKnown`) gives the outage grace.

```php
$client = $this->makeBillingClient(failurePolicy: FailurePolicy::LastKnown, queuedRefills: true, graceUnits: 50);
$this->transport()->takeDown();

expect($client->reserve('org_new', 'api.calls', 20)->grace)->toBeTrue();
```

## Testing admission

`FakeServiceAccessTransport` answers the service-access check. Script who is let in with
`allow($subject, $organization)` and `refuse($subject, $organization, $reason)`; everyone
else is refused with `not_admitted`. It has `takeDown()`, `refuseWith()`, and `checks()`,
the number of checks that reached it. Bind it in the container, so `ServiceAccess` uses it:

```php
$this->app->instance(ServiceAccessTransport::class, $fake = new FakeServiceAccessTransport);
$fake->allow('usr_1', 'org_1');

expect(app(ServiceAccess::class)->allows('usr_1', 'org_1'))->toBeTrue();
```

Kept answers read the request method from the bound `request`; set
`app()->instance('request', Request::create('/x', 'POST'))` to test a change, and
`$this->travel(31)->seconds()` to make an answer stale. See
[Kept answers](../core-concepts/kept-answers.md).

## Deterministic ids

`makeBillingClient()` injects a deterministic reservation-id factory, so buffered and
reported usage assert cleanly without stubbing randomness.

If a fake is ever awkward to use in your own suite, that is a signal to improve the
fake — not to reach around it.

## The contract suite

The fakes are held to the server by recorded fixtures in this package's
`tests/Fixtures/server/*.json`, each copied from the controller it names. The fixture test
checks that the real transports parse every fixture and that the fakes answer in exactly
their shape. The contract suite checks the fixtures against a running server.

It runs the real HTTP transports against a server you point it at, and fails on any field
a fixture has that the server no longer sends. It is skipped unless both variables are
set, and it refuses any token that is not a sandbox (`cbt_`) token, because it writes: it provisions an
organization and, with a plan, subscribes and cancels it. Use a sandbox (`cbt_`) token.
CI never runs it against production.

```bash
BILLING_CONTRACT_URL=https://app.cboxbilling.com \
BILLING_CONTRACT_TOKEN=cbt_… \
BILLING_CONTRACT_PLAN=starter \
BILLING_CONTRACT_METER=api.calls \
composer test:contract
```

| Variable | Required | Meaning |
| --- | --- | --- |
| `BILLING_CONTRACT_URL` | yes | the server's base URL |
| `BILLING_CONTRACT_TOKEN` | yes | a sandbox API token, operator-wide or product-bound |
| `BILLING_CONTRACT_ORG` | no | act for this existing organization instead of provisioning a new one (needed for an org-scoped token) |
| `BILLING_CONTRACT_PLAN` | no | a plan key to subscribe the organization to, then cancel |
| `BILLING_CONTRACT_METER` | no | a meter key to lease one unit of |

When the server changes a response on purpose, update the fixture, then the fake, in the
same change.
