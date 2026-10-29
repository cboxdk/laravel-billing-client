<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\Stores\CacheAllowanceSnapshots;
use Cbox\Billing\Client\Testing\FakeBillingTransport;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * B3 (sandbox scenario S8): while billing was unreachable the client either admitted
 * everything (`allow`) or refused as soon as the local lease was spent (`deny`). Neither
 * enforced the allowance the client last knew. `last_known` admits up to that headroom
 * (the allowance minus what this client has drawn, which includes the unspent lease) and
 * refuses beyond it on a block meter.
 */
const SPANS = 'telemetry.spans';

function spansOrg(FakeBillingTransport $transport, string $overage = 'block', ?int $allowance = 1_000_000): void
{
    $transport
        ->grant('org_free', SPANS, $allowance ?? PHP_INT_MAX)
        ->entitlement('org_free', new Entitlement(SPANS, true, $allowance, 1.0, $overage));
}

/**
 * Run the S8 shape: 300k used before the outage with a 100k lease, then 90 × 10k during it.
 *
 * @return array{admitted: int, refused: int, unavailable: int}
 */
function runOutage(BillingClient $client, FakeBillingTransport $transport): array
{
    for ($i = 0; $i < 30; $i++) {
        $client->commit($client->reserve('org_free', SPANS, 10_000), 10_000);
    }

    $transport->takeDown();

    $tally = ['admitted' => 0, 'refused' => 0, 'unavailable' => 0];

    for ($i = 0; $i < 90; $i++) {
        try {
            $client->commit($client->reserve('org_free', SPANS, 10_000), 10_000);
            $tally['admitted']++;
        } catch (QuotaExceeded) {
            $tally['refused']++;
        } catch (BillingUnavailable) {
            $tally['unavailable']++;
        }
    }

    return $tally;
}

it('enforces the last known allowance during an outage and reports it once on recovery', function (): void {
    spansOrg($this->transport());
    $signals = $this->recordSignals();
    $client = $this->makeBillingClient(leaseSize: 100_000, refillThreshold: 20_000, failurePolicy: FailurePolicy::LastKnown);

    $tally = runOutage($client, $this->transport());

    // 300k used and a 100k lease unspent when billing went away: 700k of headroom.
    expect($tally)->toBe(['admitted' => 70, 'refused' => 20, 'unavailable' => 0])
        ->and($this->usageBuffer()->cumulative('org_free', SPANS))->toBe(1_000_000)
        ->and(collect($signals->denied)->pluck('reason')->unique()->all())->toBe(['outage_limit']);

    $this->transport()->bringUp();

    expect($client->report('org_free'))->toBe(1)
        ->and($this->transport()->reportedCumulative('org_free', SPANS))->toBe(1_000_000);

    $client->report('org_free');

    expect($this->transport()->reportedCumulative('org_free', SPANS))->toBe(1_000_000);
});

it('keeps allow and deny as explicit overrides', function (FailurePolicy $policy, array $expected): void {
    spansOrg($this->transport());
    $client = $this->makeBillingClient(leaseSize: 100_000, refillThreshold: 20_000, failurePolicy: $policy);

    expect(runOutage($client, $this->transport()))->toBe($expected);
})->with([
    'allow admits everything' => [FailurePolicy::Allow, ['admitted' => 90, 'refused' => 0, 'unavailable' => 0]],
    'deny stops at the lease' => [FailurePolicy::Deny, ['admitted' => 10, 'refused' => 0, 'unavailable' => 80]],
]);

it('keeps admitting a bill-overage meter during an outage', function (): void {
    spansOrg($this->transport(), overage: 'bill');
    $client = $this->makeBillingClient(leaseSize: 100_000, refillThreshold: 20_000, failurePolicy: FailurePolicy::LastKnown);

    expect(runOutage($client, $this->transport()))->toBe(['admitted' => 90, 'refused' => 0, 'unavailable' => 0]);
});

it('keeps admitting an unlimited meter during an outage', function (): void {
    spansOrg($this->transport(), allowance: null);
    $client = $this->makeBillingClient(leaseSize: 100_000, refillThreshold: 20_000, failurePolicy: FailurePolicy::LastKnown);

    expect(runOutage($client, $this->transport()))->toBe(['admitted' => 90, 'refused' => 0, 'unavailable' => 0]);
});

it('fails closed past the lease when it never learned the entitlement', function (): void {
    // A lease but no entitlement: nothing to enforce against, so it behaves like deny.
    $this->transport()->grant('org_free', SPANS, 1_000_000);
    $client = $this->makeBillingClient(leaseSize: 100_000, refillThreshold: 20_000, failurePolicy: FailurePolicy::LastKnown);

    expect(runOutage($client, $this->transport()))->toBe(['admitted' => 10, 'refused' => 0, 'unavailable' => 80]);
});

it('answers can() from the last known headroom during an outage', function (): void {
    spansOrg($this->transport(), allowance: 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, failurePolicy: FailurePolicy::LastKnown);

    $client->commit($client->reserve('org_free', SPANS, 100), 100);
    $client->commit($client->reserve('org_free', SPANS, 100), 100);
    $this->transport()->takeDown();

    // Each spend tops the slice up, so 300 are drawn of 1,000 and 100 sit in the slice:
    // 700 can be admitted past the lease.
    expect($client->balance('org_free', SPANS))->toBe(100)
        ->and($client->can('org_free', SPANS, 700))->toBeTrue()
        ->and($client->can('org_free', SPANS, 701))->toBeFalse();
});

it('counts the unspent lease once, not on top of the headroom', function (): void {
    spansOrg($this->transport(), allowance: 1_000);
    $client = $this->makeBillingClient(leaseSize: 1_000, refillThreshold: 0, failurePolicy: FailurePolicy::LastKnown);

    $client->commit($client->reserve('org_free', SPANS, 500), 500);
    $this->transport()->takeDown();

    // The whole allowance is leased: the 500 left in the slice is all there is.
    $client->commit($client->reserve('org_free', SPANS, 500), 500);

    expect(fn () => $client->reserve('org_free', SPANS, 1))->toThrow(QuotaExceeded::class)
        ->and($client->can('org_free', SPANS, 1))->toBeFalse();
});

it('wires last_known through the container by default', function (): void {
    $down = false;

    Http::fake(function (Request $request) use (&$down) {
        if ($down) {
            throw new ConnectionException('billing is down');
        }

        return str_contains($request->url(), '/entitlements/')
            ? Http::response(['meters' => [SPANS => ['enabled' => true, 'allowance' => 400, 'weight' => 1.0, 'overage' => 'block']]])
            : Http::response(['lease_id' => 'lease_1', 'granted' => 100, 'expires_at' => '2026-10-28T12:05:00+00:00']);
    });

    config([
        'billing-client.base_url' => 'https://billing.test',
        'billing-client.api_token' => 'token',
        'billing-client.cache_store' => 'array',
    ]);

    $client = app(BillingClient::class);
    $client->commit($client->reserve('org_free', SPANS, 100), 100);
    $down = true;

    // 200 leased of 400 (the slice was topped up), 100 of it unspent: spend the slice,
    // then the 200 the last known allowance still covers, then nothing.
    $client->commit($client->reserve('org_free', SPANS, 100), 100);
    $client->commit($client->reserve('org_free', SPANS, 200), 200);

    expect(fn () => $client->reserve('org_free', SPANS, 1))->toThrow(QuotaExceeded::class);
});

it('gives back unused outage headroom on release and on a smaller commit', function (): void {
    spansOrg($this->transport(), allowance: 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, failurePolicy: FailurePolicy::LastKnown);

    $client->commit($client->reserve('org_free', SPANS, 100), 100);
    $this->transport()->takeDown();

    // 200 drawn (100 still in the slice): 800 of headroom past the lease.
    $held = $client->reserve('org_free', SPANS, 800);
    expect($held->backedByLease)->toBeFalse()
        ->and($client->can('org_free', SPANS, 101))->toBeFalse();

    $client->release($held);
    expect($client->can('org_free', SPANS, 800))->toBeTrue();

    $client->commit($client->reserve('org_free', SPANS, 800), 300);
    expect($client->can('org_free', SPANS, 500))->toBeTrue()
        ->and($client->can('org_free', SPANS, 501))->toBeFalse();
});

it('rolls a refused outage set back without spending its headroom', function (): void {
    spansOrg($this->transport(), allowance: 1_000);
    $this->transport()->grant('org_free', 'api.calls', 10);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0, failurePolicy: FailurePolicy::LastKnown);

    $client->commit($client->reserve('org_free', SPANS, 100), 100);
    $this->transport()->takeDown();

    // api.calls has no known entitlement, so the set fails and the spans bucket unwinds.
    expect(fn () => $client->reserve('org_free', [SPANS => 800, 'api.calls' => 1]))->toThrow(BillingUnavailable::class)
        ->and($client->can('org_free', SPANS, 800))->toBeTrue();
});

it('makes last_known the default policy', function (): void {
    expect(FailurePolicy::default())->toBe(FailurePolicy::LastKnown)
        ->and(FailurePolicy::fromConfig(null))->toBe(FailurePolicy::LastKnown)
        ->and(FailurePolicy::fromConfig('nonsense'))->toBe(FailurePolicy::LastKnown)
        ->and(FailurePolicy::fromConfig('last_known'))->toBe(FailurePolicy::LastKnown)
        ->and(FailurePolicy::fromConfig('allow'))->toBe(FailurePolicy::Allow)
        ->and(config('billing-client.fail'))->toBe('last_known');
});

it('learns from a short grant that billing has nothing left, and from an over-grant that a period began', function (): void {
    $snapshots = new CacheAllowanceSnapshots(new Repository(new ArrayStore));
    $snapshots->remember(new Entitlements('org_free', [SPANS => new Entitlement(SPANS, true, 1_000, 1.0, 'block')]));

    // Billing granted 50 of the 100 asked: nothing is left beyond what the client holds.
    $snapshots->recordLease('org_free', SPANS, 100, 50);
    expect($snapshots->headroom('org_free', SPANS))->toBe(0);

    // A full grant past the allowance can only come from a new period: count it alone.
    $snapshots->recordLease('org_free', SPANS, 100, 100);
    expect($snapshots->headroom('org_free', SPANS))->toBe(900);
});
