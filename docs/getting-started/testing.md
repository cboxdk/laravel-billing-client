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
  return.
- **`takeDown()` / `bringUp()`** — simulate an unreachable billing service (a connection
  error, `BillingUnavailable`) to exercise the fail-open / fail-closed policy.
- **`refuseWith($status)`** — answer every call with a status: `401` for a revoked token
  (`BillingRefused`, which fails closed under every policy), `429` or `503` for load
  shedding (`BillingUnavailable`).
- **`leasedOut()`, `reportedCumulative()`, `leaseCalls()`, `reportCalls()`** —
  assertions on what reached billing.

`FakeManagementTransport` answers as the management API does: an organization with no
subscription reads as `null`, an unknown plan is a 404, a subscribe returns
`payment_intent: null`, and cancel defaults to period end. It has `takeDown()` and
`refuseWith()` too, and `requireProvisionedOrganizations()` to refuse a subscribe for an
organization you never provisioned.

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
set, and it refuses a live (`cbl_`) token, because it writes: it provisions an
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
