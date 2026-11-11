<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Remote billing service
    |--------------------------------------------------------------------------
    |
    | `api_url` is the Billing API this app leases allowance from and reports usage
    | to: the API host with its version, `https://api.cboxbilling.com/v1` for the
    | hosted service, `https://<api host>/v1` for one with CBOX_BILLING_API_HOST set.
    | Every call appends its path to it (`…/v1/leases`). `api_token` is the bearer
    | token the transports authenticate with.
    |
    | `base_url` is the old setting: the Billing APP root (`https://app.cboxbilling.com`),
    | to which the client appends `/api/v1`. It is used only when `api_url` is empty, so
    | an app keeps working until it changes env; that address answers with Deprecation
    | headers. With neither set, the hosted API is used.
    |
    */

    'api_url' => env('BILLING_CLIENT_API_URL', env('CBOX_BILLING_API_URL')),

    // Deprecated: the app root; `/api/v1` is appended. Use `api_url`.
    'base_url' => env('BILLING_CLIENT_BASE_URL'),

    'api_token' => env('BILLING_CLIENT_API_TOKEN'),

    // Product-bound publisher credential with definitions:write only; no customer grants.
    'definition_token' => env('BILLING_CLIENT_DEFINITION_TOKEN'),

    'timeout' => (int) env('BILLING_CLIENT_TIMEOUT', 5),

    // Seconds to establish the connection, on every call. A Billing host that does not
    // answer at all fails this fast instead of holding a request for the full timeout.
    'connect_timeout' => (int) env('BILLING_CLIENT_CONNECT_TIMEOUT', 2),

    // The total timeout of a lease refill: the one call a request may still wait on (only
    // when its local slice is empty), so it gives up sooner than other calls.
    'lease_timeout' => (int) env('BILLING_CLIENT_LEASE_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Kept answers (stale-while-revalidate)
    |--------------------------------------------------------------------------
    |
    | Billing's answers to the questions a page asks (may this person use the service, what
    | is this organization's subscription) are KEPT in the cache store with when they were
    | asked, so no page waits on Billing and a Billing outage does not take pages down:
    |
    |   fresh_for — seconds an answer is used as is, by everyone.
    |   max_age   — up to this age a safe request (GET, HEAD) is served the kept answer at
    |               once and a background job asks again; past it, the request waits once.
    |   keep_for  — how long an answer stays kept at all. While Billing is failing, a safe
    |               request is served the last answer up to this age.
    |
    | A change (POST, PUT, PATCH, DELETE), `acting: true` and work outside a request never
    | act on a stale answer. See docs/core-concepts/kept-answers.md.
    |
    */

    'kept' => [
        'fresh_for' => (int) env('BILLING_CLIENT_KEPT_FRESH_FOR', 30),
        'max_age' => (int) env('BILLING_CLIENT_KEPT_MAX_AGE', 86_400),
        'keep_for' => (int) env('BILLING_CLIENT_KEPT_KEEP_FOR', 604_800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background jobs
    |--------------------------------------------------------------------------
    |
    | Where the refresh of a stale kept answer and the top-up of a low lease are queued.
    | Null uses the app's default connection and queue. Use a real queue: on the `sync`
    | connection the job runs inside the request that queued it.
    |
    */

    'queue' => [
        'connection' => env('BILLING_CLIENT_QUEUE_CONNECTION'),
        'name' => env('BILLING_CLIENT_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service access (admission)
    |--------------------------------------------------------------------------
    |
    | The per-page "may this person use the service?" check
    | (`POST /v1/access/services/{service}/check`), answered by
    | Cbox\Billing\Client\Access\ServiceAccess from a kept answer. `service` is this app's
    | service key in Billing. The token needs the `access:enroll` scope; the URL and token
    | fall back to the CBOX_SERVICE_ACCESS_* variables apps already set, then to
    | `api_url` and `api_token`. A `url` that does not end in `/v1` is taken as the old
    | app root and gets `/api/v1`.
    |
    */

    'service_access' => [
        'service' => env('BILLING_CLIENT_SERVICE'),
        'url' => env('BILLING_CLIENT_ACCESS_URL', env('CBOX_SERVICE_ACCESS_URL')),
        'token' => env('BILLING_CLIENT_ACCESS_TOKEN', env('CBOX_SERVICE_ACCESS_TOKEN')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lease sizing
    |--------------------------------------------------------------------------
    |
    | `lease_size` is how many units the SDK leases per (org, meter) refill — a
    | larger slice means fewer round-trips but more bounded overshoot (≈ lease_size ×
    | nodes). `refill_threshold` is the local remaining balance at or below which a
    | reservation triggers a background-friendly refill so the hot path rarely blocks
    | on the network.
    |
    */

    'lease_size' => (int) env('BILLING_CLIENT_LEASE_SIZE', 100),

    'refill_threshold' => (int) env('BILLING_CLIENT_REFILL_THRESHOLD', 20),

    /*
    |--------------------------------------------------------------------------
    | Refill mode and circuit breaker
    |--------------------------------------------------------------------------
    |
    | `refill` is how a lease at or below `refill_threshold` is topped up:
    |
    |   'queue' — the default: a unique background job tops it up and the request keeps
    |             serving from the local slice. No request waits on Billing for a top-up.
    |   'sync'  — inline in the request, as before 0.5.0.
    |
    | Only a slice too empty for the hold still refills inside the request, with the short
    | `lease_timeout`. After such a refill finds Billing unreachable, the breaker stays open
    | for `refill_breaker` seconds: no request tries Billing synchronously meanwhile, the
    | failure policy (below) decides at once. 0 turns the breaker off.
    |
    */

    'refill' => env('BILLING_CLIENT_REFILL', 'queue'),

    'refill_breaker' => (int) env('BILLING_CLIENT_REFILL_BREAKER', 30),

    /*
    |--------------------------------------------------------------------------
    | Single-flight refill lock
    |--------------------------------------------------------------------------
    |
    | A burst that empties a lease is coalesced behind a per-(org, meter) cache lock
    | so it triggers ONE refill round-trip, not a thundering herd. `refill_lock_ttl`
    | is how long (seconds) the lock is held while a refill is in flight; the caller
    | that holds it fetches, and concurrent callers wait up to `refill_lock_wait`
    | seconds and then reuse the freshly-leased slice. Requires a cache store that
    | supports atomic locks (Redis/Memcached/database/array); the file/null stores do
    | not, and the refill runs directly (correct, just not coalesced).
    |
    */

    'refill_lock_ttl' => (int) env('BILLING_CLIENT_REFILL_LOCK_TTL', 10),

    'refill_lock_wait' => (int) env('BILLING_CLIENT_REFILL_LOCK_WAIT', 5),

    /*
    |--------------------------------------------------------------------------
    | Local reservation recovery
    |--------------------------------------------------------------------------
    |
    | A held reservation is recorded with a TTL so a request that crashes before it
    | commits or releases does not strand its leased units for the rest of the period.
    | `reservation_ttl` (seconds) is how long a hold lives before the
    | `billing:sweep-reservations` command returns its units to the local slice.
    | Schedule that command at roughly this cadence to bound leaked capacity.
    |
    */

    'reservation_ttl' => (int) env('BILLING_CLIENT_RESERVATION_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Usage reporting
    |--------------------------------------------------------------------------
    |
    | How often (seconds) the background reporter should flush the cumulative usage
    | ledger to billing. Usage is reported CUMULATIVELY and is self-correcting: a
    | dropped report backfills on the next flush because the running total already
    | includes the lost delta. Schedule `billing:report-usage` at this cadence.
    |
    */

    'report_interval' => (int) env('BILLING_CLIENT_REPORT_INTERVAL', 30),

    /*
    |--------------------------------------------------------------------------
    | Infrastructure failure policy
    |--------------------------------------------------------------------------
    |
    | How a request resolves when the SDK can NEITHER take from the local lease NOR
    | reach billing to refill (an infrastructure fault, not an exhausted allowance):
    |
    |   'last_known' — the default: enforce the allowance the client last knew. Admit up
    |                  to the allowance minus what this client has drawn (the unspent
    |                  lease is spent first), then refuse with QuotaExceeded on a hard-
    |                  limit meter. A `bill`-overage or unlimited meter keeps admitting.
    |                  With no known entitlement for the meter it refuses, as 'deny'.
    |   'allow'      — fail OPEN: admit every request, even past the allowance.
    |   'deny'       — fail CLOSED: refuse as soon as the local lease is spent.
    |
    | Under every policy, usage is still buffered and reconciled from the cumulative
    | ledger once billing is reachable again. An EXHAUSTED allowance (billing granted
    | zero) is a semantic hard limit and always fails closed — a denial, not an outage.
    |
    | `snapshot_refresh` is how often (seconds) 'last_known' re-reads an organization's
    | entitlements. The read rides on a lease refill, at most once per interval.
    |
    */

    'fail' => env('BILLING_CLIENT_FAIL', 'last_known'),

    /*
    | Outage grace (`last_known` only). While Billing is unreachable and the last known
    | allowance does not cover a request (the entitlement was never learned, or a capped
    | meter has no headroom left in what the client last knew), admit up to `units` per
    | (organization, meter) per `window` seconds instead of refusing. `meters` overrides
    | `units` for one meter, e.g. ['storage.bytes' => 50_000_000]. 0 turns grace off. A
    | meter Billing last said is disabled gets none. What grace admits is committed and
    | reported like any usage, so Billing settles the overage once it is back.
    */

    'grace' => [
        'units' => (int) env('BILLING_CLIENT_GRACE_UNITS', 100),
        'window' => (int) env('BILLING_CLIENT_GRACE_WINDOW', 3600),
        'meters' => [],
    ],

    'snapshot_refresh' => (int) env('BILLING_CLIENT_SNAPSHOT_REFRESH', 300),

    /*
    |--------------------------------------------------------------------------
    | Usage buffer
    |--------------------------------------------------------------------------
    |
    | Where committed usage is durably appended before it is reported. Two drivers:
    |
    |   'cache'    — atomic-increment cache counters (the default). Point it at a
    |                PERSISTENT cache store; a VOLATILE cache (an in-memory or
    |                LRU-evicting store) can silently drop unreported usage on eviction
    |                or restart, losing units.
    |   'database' — a relational table (crash-safe, survives eviction and restart).
    |                Publish and run the package migration first:
    |                `php artisan vendor:publish --tag=billing-client-migrations`.
    |
    | `buffer_table` and `buffer_connection` apply to the database driver; a null
    | connection uses the app's default database connection.
    |
    */

    'buffer' => env('BILLING_CLIENT_BUFFER', 'cache'),

    'buffer_table' => env('BILLING_CLIENT_BUFFER_TABLE', 'billing_client_usage'),

    'buffer_connection' => env('BILLING_CLIENT_BUFFER_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Local store
    |--------------------------------------------------------------------------
    |
    | The cache store backing the node-local lease counters, the reservation registry,
    | and (for the cache buffer) the usage ledger. Any atomic-increment cache driver
    | works (array in tests, Redis/database in production). `null` uses the app's
    | default cache store.
    |
    */

    'cache_store' => env('BILLING_CLIENT_CACHE_STORE'),

    'prefix' => env('BILLING_CLIENT_PREFIX', 'cbox-billing-client'),

];
