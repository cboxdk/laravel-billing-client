<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Jobs\RefreshKeptAnswer;
use Cbox\Billing\Client\Kept\EntitlementsQuestion;
use Cbox\Billing\Client\Kept\KeptChanges;
use Cbox\Billing\Client\Kept\KeptEntitlements;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config(['billing-client.cache_store' => 'array']);
    $this->app->instance(BillingTransport::class, $this->transport());
    $this->transport()->entitlement('org_1', new Entitlement('brain.calls', true, 25_000, 1.0, 'block'));
    // An override: unlimited, billed beyond. Billing sends a null allowance, never 0.
    $this->transport()->entitlement('org_1', new Entitlement('brain.storage', true, null, 0.5, 'bill'));
    $this->transport()->entitlement('org_1', new Entitlement('brain.exports', false, null, 1.0, 'block'));
});

function keptEntitlements(): KeptEntitlements
{
    return app(KeptEntitlements::class);
}

it('reads the entitlements once and answers from the kept copy, unchanged', function (): void {
    $entitlements = keptEntitlements()->entitlements('org_1');
    $this->transport()->takeDown();
    $kept = keptEntitlements()->entitlements('org_1');

    expect($kept)->toEqual($entitlements)
        ->and($kept->org)->toBe('org_1')
        ->and($kept->for('brain.calls'))->toEqual(new Entitlement('brain.calls', true, 25_000, 1.0, 'block'))
        ->and($kept->for('brain.calls')->isCapped())->toBeTrue()
        ->and($kept->for('brain.storage')->allowance)->toBeNull()
        ->and($kept->for('brain.storage')->isUnlimited())->toBeTrue()
        ->and($kept->for('brain.storage')->weight)->toBe(0.5)
        ->and($kept->enabled('brain.exports'))->toBeFalse()
        ->and($kept->for('brain.unknown'))->toBeNull();
});

it('serves the kept entitlements on a page while Billing is down, and asks again in the background', function (): void {
    Bus::fake();
    keptEntitlements()->entitlements('org_1');
    $this->travel(3)->days();
    $this->transport()->takeDown();
    app()->instance('request', Request::create('/page', 'GET'));

    expect(keptEntitlements()->entitlements('org_1')->for('brain.calls')?->allowance)->toBe(25_000);
    Bus::assertDispatched(RefreshKeptAnswer::class, fn (RefreshKeptAnswer $job): bool => $job->question == new EntitlementsQuestion('org_1'));
});

it('reads them live before a change', function (): void {
    keptEntitlements()->entitlements('org_1');
    $this->travel(31)->seconds();
    $this->transport()->entitlement('org_1', new Entitlement('brain.calls', true, 50_000, 1.0, 'block'));

    expect(keptEntitlements()->entitlements('org_1', acting: true)->for('brain.calls')?->allowance)->toBe(50_000);

    $this->travel(31)->seconds();
    $this->transport()->takeDown();
    app()->instance('request', Request::create('/change', 'POST'));

    expect(fn () => keptEntitlements()->entitlements('org_1'))->toThrow(BillingUnavailable::class);
});

it('reads them again once Billing said the organization or a plan changed', function (string $type, array $payload): void {
    keptEntitlements()->entitlements('org_1');
    $this->transport()->entitlement('org_1', new Entitlement('brain.calls', true, 50_000, 1.0, 'block'));

    app(KeptChanges::class)->fromWebhook($type, $payload);
    app()->instance('request', Request::create('/page', 'GET'));

    expect(keptEntitlements()->entitlements('org_1')->for('brain.calls')?->allowance)->toBe(50_000);
})->with([
    'an override on the subscription' => ['subscription.updated', ['organization_ref' => 'org_1']],
    'a plan' => ['plan.changed', ['plan' => 'brain-team']],
]);

it('drops or refreshes the kept entitlements on request', function (): void {
    keptEntitlements()->entitlements('org_1');
    $this->transport()->entitlement('org_1', new Entitlement('brain.calls', true, 50_000, 1.0, 'block'));

    expect(keptEntitlements()->entitlements('org_1')->for('brain.calls')?->allowance)->toBe(25_000);
    keptEntitlements()->forget('org_1');
    expect(keptEntitlements()->entitlements('org_1')->for('brain.calls')?->allowance)->toBe(50_000);

    $this->transport()->entitlement('org_1', new Entitlement('brain.calls', true, null, 1.0, 'bill'));
    expect(keptEntitlements()->refresh('org_1')->for('brain.calls')?->isUnlimited())->toBeTrue();
});

it('reads a kept copy it cannot parse as nothing kept', function (): void {
    keptEntitlements()->entitlements('org_1');
    app('cache')->store('array')->put('cbox-billing-client:kept:'.hash('sha256', (new EntitlementsQuestion('org_1'))->key()), ['value' => ['meters' => ['brain.calls' => ['enabled' => true, 'allowance' => '25000', 'weight' => 1.0, 'overage' => 'block']]], 'at' => time()], 60);
    app()->instance('request', Request::create('/page', 'GET'));

    expect(keptEntitlements()->entitlements('org_1')->for('brain.calls')?->allowance)->toBe(25_000);
});
