<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Enums\FeatureSource;
use Cbox\Billing\Client\Enums\FeatureType;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Jobs\RefreshKeptAnswer;
use Cbox\Billing\Client\Kept\FeaturesQuestion;
use Cbox\Billing\Client\Kept\KeptChanges;
use Cbox\Billing\Client\Kept\KeptFeatures;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config(['billing-client.cache_store' => 'array']);
    $this->app->instance(BillingTransport::class, $this->transport());
    $this->transport()->feature('org_1', new FeatureEntitlement('sites.custom_domains', FeatureType::Boolean, true, source: FeatureSource::Plan));
    $this->transport()->feature('org_1', new FeatureEntitlement('sites.retention_days', FeatureType::Config, true, 30, FeatureSource::Override));
});

function keptFeatures(): KeptFeatures
{
    return app(KeptFeatures::class);
}

it('reads the features once and answers from the kept copy, unchanged', function (): void {
    $features = keptFeatures()->features('org_1');
    $this->transport()->takeDown();

    expect($features->enabled('sites.custom_domains'))->toBeTrue()
        ->and(keptFeatures()->features('org_1'))->toEqual($features)
        ->and(keptFeatures()->features('org_1')->for('sites.retention_days'))->toEqual(new FeatureEntitlement('sites.retention_days', FeatureType::Config, true, 30, FeatureSource::Override))
        ->and(keptFeatures()->features('org_1')->value('sites.retention_days'))->toBe(30)
        ->and(keptFeatures()->features('org_1')->org)->toBe('org_1');
});

it('keeps a suspended account suspended', function (): void {
    $this->transport()->suspendAccount('org_1');

    expect(keptFeatures()->features('org_1')->suspended)->toBeTrue()
        ->and(keptFeatures()->features('org_1')->enabled('sites.custom_domains'))->toBeFalse();
});

it('serves the kept features on a page while Billing is down, and asks again in the background', function (): void {
    Bus::fake();
    keptFeatures()->features('org_1');
    $this->travel(3)->days();
    $this->transport()->takeDown();
    app()->instance('request', Request::create('/page', 'GET'));

    expect(keptFeatures()->features('org_1')->enabled('sites.custom_domains'))->toBeTrue();
    Bus::assertDispatched(RefreshKeptAnswer::class, fn (RefreshKeptAnswer $job): bool => $job->question == new FeaturesQuestion('org_1'));
});

it('reads them live before a change', function (): void {
    keptFeatures()->features('org_1');
    $this->travel(31)->seconds();
    $this->transport()->feature('org_1', new FeatureEntitlement('sites.custom_domains', FeatureType::Boolean, false, source: FeatureSource::Override));

    expect(keptFeatures()->features('org_1', acting: true)->enabled('sites.custom_domains'))->toBeFalse();

    $this->travel(31)->seconds();
    $this->transport()->takeDown();
    app()->instance('request', Request::create('/change', 'POST'));

    expect(fn () => keptFeatures()->features('org_1'))->toThrow(BillingUnavailable::class);
});

it('reads them again once Billing said the organization or a plan changed', function (string $type, array $payload): void {
    keptFeatures()->features('org_1');
    $this->transport()->feature('org_1', new FeatureEntitlement('sites.custom_domains', FeatureType::Boolean, false, source: FeatureSource::Default));

    app(KeptChanges::class)->fromWebhook($type, $payload);
    app()->instance('request', Request::create('/page', 'GET'));

    expect(keptFeatures()->features('org_1')->enabled('sites.custom_domains'))->toBeFalse();
})->with([
    'the subscription' => ['subscription.canceled', ['organization_ref' => 'org_1']],
    'a plan' => ['plan.changed', ['plan' => 'pro']],
]);

it('drops or refreshes the kept features on request', function (): void {
    keptFeatures()->features('org_1');
    $this->transport()->feature('org_1', new FeatureEntitlement('sites.custom_domains', FeatureType::Boolean, false, source: FeatureSource::Default));

    expect(keptFeatures()->features('org_1')->enabled('sites.custom_domains'))->toBeTrue();
    keptFeatures()->forget('org_1');
    expect(keptFeatures()->features('org_1')->enabled('sites.custom_domains'))->toBeFalse();

    $this->transport()->feature('org_1', new FeatureEntitlement('sites.custom_domains', FeatureType::Boolean, true, source: FeatureSource::Plan));
    expect(keptFeatures()->refresh('org_1')->enabled('sites.custom_domains'))->toBeTrue();
});

it('reads a kept copy it cannot parse as nothing kept', function (): void {
    keptFeatures()->features('org_1');
    app('cache')->store('array')->put('cbox-billing-client:kept:'.hash('sha256', (new FeaturesQuestion('org_1'))->key()), ['value' => ['suspended' => false, 'features' => ['x' => ['type' => 'nonsense', 'enabled' => true, 'value' => null, 'source' => 'plan']]], 'at' => time()], 60);
    app()->instance('request', Request::create('/page', 'GET'));

    expect(keptFeatures()->features('org_1')->enabled('sites.custom_domains'))->toBeTrue();
});
