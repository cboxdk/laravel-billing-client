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

Two readers are built on kept answers:

- `ServiceAccess` answers the per-page admission check: may this person use the service?
- `KeptSubscriptions` reads an organization's subscription (plan, status, limits).

Both use the same rules, from `Cbox\Billing\Client\Contracts\KeptAnswers`.

## The rules

Every answer is kept in the cache store together with the time it was asked. That
includes a "no" (refused, waitlisted) and "no subscription".

| Situation | What happens |
| --- | --- |
| Answer younger than `fresh_for` (30 s) | Used as is, by everyone. |
| Safe request (GET, HEAD, OPTIONS), answer older than `fresh_for` | The kept answer is returned at once, and one background refresh is queued. |
| A change (POST, PUT, PATCH, DELETE), or `acting: true` | A stale answer is not used. Billing is asked now and the answer is kept. |
| Outside an HTTP request (a queued job, a command) | Same as a change: only a fresh answer is used. |
| Nothing kept, or older than `max_age` (1 day) | Billing is asked now. The request waits this one time. |
| Billing fails while an answer is kept, on a safe request | The kept answer is served, up to `keep_for` (7 days) old. |
| Billing fails and nothing is kept, or it is a change | The failure is thrown (`BillingUnavailable`, `BillingRefused`), as before. |

The refresh is a queued `RefreshKeptAnswer` job. It is unique per question while queued,
so a burst of pages queues one refresh. It tries once. When Billing is still away, the
job reports the failure to the app's exception handler and the kept answer stays. The next
refresh for the same question is queued no sooner than 60 seconds later.

Use a real queue. On the `sync` connection the "background" refresh runs inside the
request that queued it.

## What is stored

Only plain data: arrays of arrays and scalars. No object is ever unserialized from the
cache. Each value is checked when it is read: its shape, its time and whether it is an
answer to the question. Anything else counts as nothing kept, and Billing is asked.

## Configuration

```php
// config/billing-client.php
'kept' => [
    'fresh_for' => 30,       // BILLING_CLIENT_KEPT_FRESH_FOR
    'max_age' => 86_400,     // BILLING_CLIENT_KEPT_MAX_AGE
    'keep_for' => 604_800,   // BILLING_CLIENT_KEPT_KEEP_FOR
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
$subscriptions->forget($org->cbox_id);          // on the plan.changed webhook
$subscriptions->remember($org->cbox_id, $subscription); // the answer to your own change
```

## Running on a long-lived server

Kept answers find out what kind of request they serve from
`Cbox\Billing\Client\Kept\CurrentRequest`. It treats a process whose `runningInConsole()` is
true as "no HTTP request". If you serve HTTP from such a process, bind a subclass of
`CurrentRequest` that returns the current request's method.
