<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\Jobs\RefillLease;
use Cbox\Billing\Client\Leasing\OutageGrace;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

const CALLS = 'api.calls';

it('queues the top-up of a low lease and keeps serving from the local slice', function (): void {
    $this->transport()->grant('org_a', CALLS, 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 20, queuedRefills: true);

    // The first hold has nothing local: that one refill is synchronous.
    $client->commit($client->reserve('org_a', CALLS, 10), 10);
    expect($this->transport()->leaseCalls())->toBe(1);

    // Down to the threshold: the top-up is queued, not made, and the request is served.
    $client->commit($client->reserve('org_a', CALLS, 70), 70);
    $client->commit($client->reserve('org_a', CALLS, 5), 5);

    expect($this->transport()->leaseCalls())->toBe(1)
        ->and($this->queuedRefills())->toBe([['org_a', CALLS]])
        ->and($client->balance('org_a', CALLS))->toBe(15);

    expect($this->runQueuedRefills())->toBe(100)
        ->and($client->balance('org_a', CALLS))->toBe(115)
        ->and($this->transport()->leaseCalls())->toBe(2);
});

it('lets a queued top-up do nothing when the slice was refilled meanwhile', function (): void {
    $this->transport()->grant('org_a', CALLS, 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 20, queuedRefills: true);

    $client->commit($client->reserve('org_a', CALLS, 85), 85);
    $client->commit($client->reserve('org_a', CALLS, 20), 20);

    // The slice too short for 20 refilled inline, so the queued top-up finds it above the threshold.
    expect($this->runQueuedRefills())->toBe(0)
        ->and($this->transport()->leaseCalls())->toBe(2);
});

it('does not wait on Billing for a low lease while it is down', function (): void {
    $this->transport()->grant('org_a', CALLS, 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 50, queuedRefills: true);
    $client->commit($client->reserve('org_a', CALLS, 60), 60);
    $calls = $this->transport()->leaseCalls();
    $this->transport()->takeDown();

    for ($i = 0; $i < 4; $i++) {
        $client->commit($client->reserve('org_a', CALLS, 10), 10);
    }

    expect($this->transport()->leaseCalls())->toBe($calls)
        ->and(fn () => $this->runQueuedRefills())->toThrow(BillingUnavailable::class);
});

it('stops refilling synchronously for a while after Billing was found unreachable', function (): void {
    $this->transport()->grant('org_a', CALLS, 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, failurePolicy: FailurePolicy::Allow, queuedRefills: true, breakerCooldown: 30);
    $client->commit($client->reserve('org_a', CALLS, 100), 100);
    $this->transport()->takeDown();

    // The first empty slice tries Billing, fails and opens the breaker.
    expect($client->reserve('org_a', CALLS, 1)->backedByLease)->toBeFalse();

    // Billing is back, but within the cooldown no request tries it: still served unbacked, at once.
    $this->transport()->bringUp();
    $calls = $this->transport()->leaseCalls();

    expect($client->reserve('org_a', CALLS, 1)->backedByLease)->toBeFalse()
        ->and($this->transport()->leaseCalls())->toBe($calls);

    // After the cooldown the next empty slice probes again.
    $this->travel(31)->seconds();

    expect($client->reserve('org_a', CALLS, 1)->backedByLease)->toBeTrue()
        ->and($this->transport()->leaseCalls())->toBe($calls + 1);
});

it('lets the background top-up probe through an open breaker and close it', function (): void {
    $this->transport()->grant('org_a', CALLS, 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 20, failurePolicy: FailurePolicy::Allow, queuedRefills: true, breakerCooldown: 300);
    $client->commit($client->reserve('org_a', CALLS, 100), 100);
    $this->transport()->takeDown();
    $client->reserve('org_a', CALLS, 1);
    $calls = $this->transport()->leaseCalls();

    $this->transport()->bringUp();
    $client->reserve('org_a', CALLS, 1);
    expect($this->transport()->leaseCalls())->toBe($calls);

    // The probe refills the empty slice and closes the breaker: the next hold is lease-backed.
    expect($this->queuedRefills())->toBe([['org_a', CALLS]])
        ->and($this->runQueuedRefills())->toBe(100);

    expect($this->transport()->leaseCalls())->toBe($calls + 1)
        ->and($client->reserve('org_a', CALLS, 1)->backedByLease)->toBeTrue()
        // A hold the slice cannot cover refills synchronously again: the breaker is closed.
        ->and($client->reserve('org_a', CALLS, 100)->backedByLease)->toBeTrue()
        ->and($this->transport()->leaseCalls())->toBe($calls + 2);
});

it('admits a small grace in an outage when no allowance was ever learned, and reports it', function (): void {
    $this->transport()->grant('org_new', CALLS, 1_000);
    $client = $this->makeBillingClient(failurePolicy: FailurePolicy::LastKnown, graceUnits: 30);
    $this->transport()->takeDown();

    $held = $client->reserve('org_new', CALLS, 20);
    $client->commit($held, 15);
    $client->commit($client->reserve('org_new', CALLS, 15), 15);

    expect($held->grace)->toBeTrue()
        ->and($held->backedByLease)->toBeFalse()
        ->and(fn () => $client->reserve('org_new', CALLS, 1))->toThrow(BillingUnavailable::class)
        ->and($client->can('org_new', CALLS, 1))->toBeFalse();

    // Billing back: what grace admitted is reported for Billing to settle.
    $this->transport()->bringUp();
    $client->report();

    expect($this->transport()->reportedCumulative('org_new', CALLS))->toBe(30);
});

it('gives grace back on release and starts a new window', function (): void {
    $this->transport()->grant('org_new', CALLS, 1_000);
    $client = $this->makeBillingClient(failurePolicy: FailurePolicy::LastKnown, graceUnits: 10, graceWindow: 60);
    $this->transport()->takeDown();

    $client->release($client->reserve('org_new', CALLS, 10));
    $client->commit($client->reserve('org_new', CALLS, 10), 10);

    expect($client->can('org_new', CALLS, 1))->toBeFalse();

    $this->travel(61)->seconds();

    expect($client->can('org_new', CALLS, 10))->toBeTrue()
        ->and($client->reserve('org_new', CALLS, 10)->grace)->toBeTrue();
});

it('admits grace past an exhausted last known headroom, but never for a disabled meter', function (): void {
    $this->transport()
        ->grant('org_a', CALLS, 100)
        ->entitlement('org_a', new Entitlement(CALLS, true, 100, 1.0, 'block'))
        ->grant('org_a', 'exports', 100)
        ->entitlement('org_a', new Entitlement('exports', false, 0, 1.0, 'block'));
    $client = $this->makeBillingClient(leaseSize: 100, failurePolicy: FailurePolicy::LastKnown, graceUnits: 5);
    $client->commit($client->reserve('org_a', CALLS, 100), 100);
    $this->transport()->takeDown();

    expect($client->reserve('org_a', CALLS, 5)->grace)->toBeTrue()
        ->and(fn () => $client->reserve('org_a', CALLS, 1))->toThrow(QuotaExceeded::class)
        ->and(fn () => $client->reserve('org_a', 'exports', 1))->toThrow(QuotaExceeded::class);
});

it('keeps the old refusal when grace is off', function (): void {
    $this->transport()->grant('org_new', CALLS, 1_000);
    $client = $this->makeBillingClient(failurePolicy: FailurePolicy::LastKnown);
    $this->transport()->takeDown();

    expect(fn () => $client->reserve('org_new', CALLS, 1))->toThrow(BillingUnavailable::class);
});

it('rolls a grace bucket back with the rest of a refused set', function (): void {
    $this->transport()
        ->grant('org_a', CALLS, 1_000)
        ->grant('org_a', 'exports', 1_000)
        ->entitlement('org_a', new Entitlement('exports', false, 0, 1.0, 'block'));
    $client = $this->makeBillingClient(failurePolicy: FailurePolicy::LastKnown, graceUnits: 10);
    $client->entitlements('org_a');
    $this->transport()->takeDown();

    expect(fn () => $client->reserve('org_a', [CALLS => 10, 'exports' => 1]))->toThrow(QuotaExceeded::class)
        ->and($client->reserve('org_a', CALLS, 10)->grace)->toBeTrue();
});

it('queues one RefillLease job per lease through the container', function (): void {
    Bus::fake();
    Http::fake(['*' => Http::response(['lease_id' => 'l1', 'granted' => 100, 'expires_at' => null])]);
    config(['billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 't', 'billing-client.cache_store' => 'array']);

    $client = app(BillingClient::class);
    $client->commit($client->reserve('org_a', CALLS, 85), 85);
    $client->commit($client->reserve('org_a', CALLS, 1), 1);
    $client->commit($client->reserve('org_a', 'other', 85), 85);

    Bus::assertDispatchedTimes(RefillLease::class, 2);
    Bus::assertDispatched(RefillLease::class, fn (RefillLease $job): bool => $job->org === 'org_a' && $job->meter === CALLS && $job->tries === 1);
    expect(Http::recorded(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/leases')))->toHaveCount(2);
});

it('refills in the RefillLease job and lets the next low lease queue again', function (): void {
    Http::fake(['*' => Http::response(['lease_id' => 'l1', 'granted' => 100, 'expires_at' => null])]);
    config(['billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 't', 'billing-client.cache_store' => 'array']);

    // An app without a queue: the top-up runs once the response is out, not in the request.
    config(['queue.default' => 'sync']);
    $client = app(BillingClient::class);
    $client->commit($client->reserve('org_a', CALLS, 85), 85);
    expect($client->balance('org_a', CALLS))->toBe(15);
    $this->app->terminate();
    expect($client->balance('org_a', CALLS))->toBe(115);

    $client->commit($client->reserve('org_a', CALLS, 100), 100);
    $this->app->terminate();
    expect($client->balance('org_a', CALLS))->toBe(115)
        ->and(Http::recorded(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/leases')))->toHaveCount(3);
});

it('fails a container-wired empty slice fast while the breaker is open', function (): void {
    Bus::fake();
    $down = false;
    $calls = 0;
    Http::fake(function () use (&$down, &$calls) {
        $calls++;

        if ($down) {
            throw new ConnectionException('billing is down');
        }

        return Http::response(['lease_id' => 'l1', 'granted' => 100, 'expires_at' => null]);
    });
    config(['billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 't', 'billing-client.cache_store' => 'array', 'billing-client.fail' => 'allow']);

    $client = app(BillingClient::class);
    $client->commit($client->reserve('org_a', CALLS, 100), 100);
    $down = true;

    foreach (range(1, 3) as $ignored) {
        expect($client->reserve('org_a', CALLS, 1)->backedByLease)->toBeFalse();
    }

    expect($calls)->toBe(2);
});

it('never revives an expired grace window when a late hold gives grace back', function (): void {
    $store = new Repository(new ArrayStore);
    $grace = new OutageGrace($store, 10, 60);

    $window = $grace->take('org_a', CALLS, 10);
    expect($window)->toBeInt();

    $this->travel(61)->seconds();

    // Grace already taken in the new window is not credited by the old window's hold.
    expect($grace->take('org_a', CALLS, 4))->toBeInt();
    $grace->giveBack('org_a', CALLS, 10, $window);

    expect($grace->remaining('org_a', CALLS))->toBe(6)
        ->and($grace->take('org_a', CALLS, 7))->toBeNull()
        ->and($grace->take('org_a', CALLS, 6))->toBeInt()
        ->and($grace->remaining('org_a', CALLS))->toBe(0);
});
