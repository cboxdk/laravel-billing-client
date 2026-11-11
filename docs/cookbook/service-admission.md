---
title: Admission and plan reads that survive a Billing outage
weight: 36
description: Replace the per-page service-access check and the subscription read with kept answers, so pages render instantly and stay up while Billing is down.
---

# Admission and plan reads that survive a Billing outage

Most apps ask Billing two things on every organization page: may this person use the
service, and what plan is the organization on. Asked live, each page waits on Billing, and
each page fails while Billing is down. The client keeps both answers and refreshes them in
the background. See [Kept answers](../core-concepts/kept-answers.md) for the rules.

## Configure

```dotenv
BILLING_CLIENT_API_URL=https://api.cboxbilling.com/v1
BILLING_CLIENT_API_TOKEN=...
BILLING_CLIENT_SERVICE=cbox-telemetry       # your service key in Billing
# A token with the access:enroll scope. Falls back to CBOX_SERVICE_ACCESS_URL / _TOKEN,
# then to BILLING_CLIENT_API_URL / _API_TOKEN. A URL not ending in /v1 is read as the
# old Billing app root and gets /api/v1.
BILLING_CLIENT_ACCESS_URL=
BILLING_CLIENT_ACCESS_TOKEN=
```

Run a queue worker. The background refresh is a queued job.

## The per-page admission check

Before, a middleware asked Billing on every page:

```php
// POST /v1/access/services/{service}/check, every page
$allowed = $admissions->allowedFor($user->id, $organization->id);
```

After:

```php
use Cbox\Billing\Client\Access\ServiceAccess;

$allowed = app(ServiceAccess::class)->allows($user->id, $organization->id);
```

A page gets the kept answer at once. A stale answer is refreshed in the background. A
first visit waits for Billing once. A POST, PUT, PATCH or DELETE uses only a fresh
answer. While Billing is down, a page is served the last answer.

Pass `acting: true` where the decision has to be current even on a GET, for example the
step that grants access:

```php
$access->allows($user->id, $organization->id, acting: true);
```

`decision()` returns the reason Billing gave for a "no" (`waitlisted`, for example):

```php
$decision = $access->decision($user->id, $organization->id);

if (! $decision->allowed) {
    return redirect()->route('waitlist', ['reason' => $decision->reason]);
}
```

When the app itself admits someone (`/admit` succeeded), keep that answer, so the next page
does not show the old one:

```php
$access->remember($user->id, $organization->id, true);
```

When nothing is kept and Billing is down, `allows()` throws `BillingUnavailable`, as the
live call did. Keep your existing handling for that one case.

## Plan and subscription reads

Before, a plan read cached Billing's answer for 60 seconds and then waited on Billing again:

```php
$subscription = $cache->remember("plan:{$org->id}", 60, fn () => $billing->subscription($org->cbox_id));
```

After:

```php
use Cbox\Billing\Client\Kept\KeptSubscriptions;

$subscription = app(KeptSubscriptions::class)->subscription($org->cbox_id); // ?Subscription
```

It returns the same `Subscription` (or null for "no subscription") as
`BillingManagement::subscription()`. Drop the app's own cache around it.

- Before a change that depends on the plan (an upgrade, a limit check on create), pass
  `acting: true`.
- On the `plan.changed` webhook, call `forget($org->cbox_id)`.
- After your own subscribe, change or cancel, call `remember($org->cbox_id, $subscription)`.

## Test it

```php
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Testing\FakeServiceAccessTransport;

$this->app->instance(ServiceAccessTransport::class, $fake = new FakeServiceAccessTransport);
$fake->allow('usr_1', 'org_1')->refuse('usr_2', 'org_1', 'waitlisted');

$this->get('/dashboard')->assertOk();

$fake->takeDown();
$this->travel(5)->minutes();
$this->get('/dashboard')->assertOk(); // served from the kept answer
```
