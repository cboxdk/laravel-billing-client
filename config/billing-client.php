<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Remote billing service
    |--------------------------------------------------------------------------
    |
    | The base URL of the Cbox Billing service this app leases allowance from and
    | reports usage to, plus the bearer token the transport authenticates with. When
    | either is empty the HTTP transport is not bound and the app must bind its own
    | BillingTransport (e.g. the in-memory fake in tests).
    |
    */

    'base_url' => env('BILLING_CLIENT_BASE_URL'),

    'api_token' => env('BILLING_CLIENT_API_TOKEN'),

    'timeout' => (int) env('BILLING_CLIENT_TIMEOUT', 5),

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
    |   'allow' — fail OPEN: admit the request best-effort; usage is still buffered and
    |             reconciled from the cumulative ledger once billing is reachable again.
    |   'deny'  — fail CLOSED: refuse, for strict tenants that would rather block than
    |             admit un-leased usage during an outage.
    |
    | An EXHAUSTED allowance (billing granted zero) is a semantic hard limit and always
    | fails closed regardless of this policy — it is a denial, not an outage.
    |
    */

    'fail' => env('BILLING_CLIENT_FAIL', 'allow'),

    /*
    |--------------------------------------------------------------------------
    | Local store
    |--------------------------------------------------------------------------
    |
    | The cache store backing the node-local lease counters and the durable usage
    | ledger. Any atomic-increment cache driver works (array in tests, Redis/database
    | in production). `null` uses the app's default cache store.
    |
    */

    'cache_store' => env('BILLING_CLIENT_CACHE_STORE'),

    'prefix' => env('BILLING_CLIENT_PREFIX', 'cbox-billing-client'),

];
