---
title: Kept answers
weight: 29
description: How the client keeps Billing's answers and refreshes them in the background, so pages render from local data and stay up while Billing is down.
---

# Kept answers

A page should not wait on Billing, and a page should not fail because Billing is down.
The client keeps Billing's answers to the questions a page asks, and asks again in the
background when an answer gets old. This is stale-while-revalidate. A cache with a TTL is
not the same thing: when its entry runs out, the next request waits on Billing again, and
during an outage it fails.

Three readers are built on kept answers:

- `ServiceAccess` answers the admission check: may this person use the service?
- `KeptSubscriptions` reads an organization's subscription (plan, status, limits).
- `KeptFeatures` reads an organization's features (plan grants and overrides).

All use the same rules, from `Cbox\Billing\Client\Contracts\KeptAnswers`.

## The rules

Every answer is kept in the cache store together with the time it was asked. That
includes a "no" (refused, waitlisted) and "no subscription".

| Situation | What happens |
| --- | --- |
| Answer younger than `fresh_for` (30 s) | Used as is, by everyone. |
| Safe request (GET, HEAD, OPTIONS), answer older than `fresh_for` | The kept answer is returned at once, and one background refresh is queued. |
| A change (POST, PUT, PATCH, DELETE), or `acting: true` | A stale answer is not used. Billing is asked now and the answer is kept. |
| Outside an HTTP request (a queued job, a command) | Same as a change: only a fresh answer is used. |
| Nothing kept, or older than `max_age` (30 days) | Billing is asked now. The request waits this one time. |
| Billing said the organization, or a plan, changed after the answer was asked | Billing is asked now, on a page too. See [When Billing says something changed](#when-billing-says-something-changed). |
| A kept "no" from the admission check, older than 5 seconds | Billing is asked now. See [A refusal is not served stale](#a-refusal-is-not-served-stale). |
| Billing fails while an answer is kept, on a safe request | The kept answer is served, up to `keep_for` (60 days) old. |
| Billing fails and nothing is kept, or it is a change | The failure is thrown (`BillingUnavailable`, `BillingRefused`), as before. |

So a returning person's page waits on Billing only when nothing is known about them, or
when Billing has said their organization changed. A person who comes back after three weeks
is let in on the kept answer at once, with Billing up or down.

The refresh is a queued `RefreshKeptAnswer` job. It is unique per question while queued,
so a burst of pages queues one refresh. It tries once. When Billing is still away, the
job reports the failure to the app's exception handler and the kept answer stays. The next
refresh for the same question is queued no sooner than 60 seconds later.

## One read per request

Within one HTTP request, a question is read from the cache store once and Billing is asked
at most once, however many times the request asks. Nine admission checks in one sign-in
callback are one cache read. A request that found Billing failing on a question does not
try it again; the next request does. `remember()`, `forget()` and a refresh are seen at
once by the request that made them.

## Without a queue

Use a real queue. An app that runs none (the `sync` connection) still does not wait: while
an HTTP request is served, the refresh runs after the response has been sent. The same
holds for the queued lease top-up. Only a command on the `sync` connection runs the job
inline.

## When Billing says something changed

A kept "yes" must not outlive the subscription it came from. Tell the client what a Billing
webhook said, after you have verified its signature:

```php
use Cbox\Billing\Client\Kept\KeptChanges;

app(KeptChanges::class)->fromWebhook($event['type'], $event['data']);
```

| Event | Effect |
| --- | --- |
| `subscription.*`, `customer.*`, `access.*` with an `organization_ref` | Every answer kept about that organization before now is asked again on its next use. |
| `plan.*` (`plan.changed`) | Every kept answer is asked again on its next use. |
| Anything else | Nothing. |

Nothing is deleted. The mark is the time of the change, kept for `keep_for`. An answer
asked at or before it is not served: the next request that needs it waits for Billing
once, on a page too. If Billing cannot answer then, a page is still served the kept
answer and a change still throws. Call `organization($ref)` or `everything()` directly
when the app learns of a change another way.

### How long a revoked organization keeps access

| The app | Longest a "yes" outlives the change |
| --- | --- |
| Handles the webhook as above | Until the webhook arrives. The next request asks Billing. |
| No webhook, or a change Billing sends no event for (an access grant revoked by hand) | A change (POST, PUT, PATCH, DELETE): `fresh_for`, 30 seconds. A page: the first page view after those 30 seconds is still served the old answer and queues the refresh; the page view after the refresh ran gets the new one. |
| Billing is down the whole time | Pages keep the old answer until Billing answers again, up to `keep_for`. Changes fail. |

## A refusal is not served stale

Somebody Billing has just let in (an invitation, an operator) must not be turned away by
an old "no". The admission check therefore reuses a "no" for 5 seconds only, enough for
one request's repeated asks, and then asks Billing again in the request. Nobody refused is
inside the app, so no working page waits for this. While Billing is failing, a page is
still served the kept "no".

A question decides this for its own answers by implementing
`Cbox\Billing\Client\Contracts\JudgesKeptAnswer`.

## What is stored

Only plain data: arrays of arrays and scalars. No object is ever unserialized from the
cache. Each value is checked when it is read: its shape, its time and whether it is an
answer to the question. Anything else counts as nothing kept, and Billing is asked.

## Configuration

```php
// config/billing-client.php
'kept' => [
    'fresh_for' => 30,       // BILLING_CLIENT_KEPT_FRESH_FOR
    'max_age' => 2_592_000,  // BILLING_CLIENT_KEPT_MAX_AGE   (30 days)
    'keep_for' => 5_184_000, // BILLING_CLIENT_KEPT_KEEP_FOR  (60 days)
],
'queue' => [
    'connection' => null,    // BILLING_CLIENT_QUEUE_CONNECTION
    'name' => null,          // BILLING_CLIENT_QUEUE
],
```

Answers live in the `cache_store` the rest of the client uses. Point it at a shared store
(Redis, the database), so every web node and worker sees the same answers.

## Keeping your own answers

`KeptAnswers` works for any question. Implement `Cbox\Billing\Client\Contracts\KeptQuestion`:

```php
use Cbox\Billing\Client\Contracts\KeptQuestion;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Illuminate\Contracts\Container\Container;

// Implement OrganizationQuestion instead to have KeptChanges cover the answer.
final readonly class ResourceLimitsQuestion implements KeptQuestion
{
    public function __construct(public string $organization) {}

    public function key(): string
    {
        return 'limits:'.$this->organization;
    }

    public function ask(Container $app): array
    {
        return array_map(
            static fn ($limit): array => ['key' => $limit->key, 'limit' => $limit->limit],
            array_values($app->make(ManagementTransport::class)->resourceLimits($this->organization)),
        );
    }

    public function accepts(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}

$limits = app(KeptAnswers::class)->answer(new ResourceLimitsQuestion($org));
```

A question is serialized onto the queue, so it holds only strings and ints. Its answer is
plain data.

## When the app knows better

When the app has just admitted someone, or a webhook says something changed, update the
kept answer instead of waiting for it to age:

```php
$access->remember($user->id, $org->id, true);   // the app itself admitted them
$access->forget($user->id, $org->id);           // the next check asks Billing
$subscriptions->remember($org->cbox_id, $subscription); // the answer to your own change
$changes->fromWebhook($type, $data);            // a Billing webhook, see above
```

## Running on a long-lived server

Kept answers find out what kind of request they serve from
`Cbox\Billing\Client\Kept\CurrentRequest`. It treats a process whose `runningInConsole()` is
true as "no HTTP request". If you serve HTTP from such a process, bind a subclass of
`CurrentRequest` that returns the current request's method.
