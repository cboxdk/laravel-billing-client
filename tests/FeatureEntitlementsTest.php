<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\FeatureReader;
use Cbox\Billing\Client\Contracts\FeatureTransport;
use Cbox\Billing\Client\Enums\FeatureSource;
use Cbox\Billing\Client\Enums\FeatureType;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\Testing\FakeBillingTransport;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlement;
use Cbox\Billing\Client\ValueObjects\OrganizationFeatures;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

it('reads boolean and typed config feature grants independently of meters', function (): void {
    $transport = new FakeBillingTransport;
    $transport->feature('org_a', new FeatureEntitlement('cortex.autoscaling', FeatureType::Boolean, true, source: FeatureSource::Plan));
    $transport->feature('org_a', new FeatureEntitlement('cortex.retention', FeatureType::Config, true, 30, FeatureSource::Override));
    $features = $transport->features('org_a');
    expect($features->enabled('cortex.autoscaling'))->toBeTrue()
        ->and($features->for('cortex.retention')?->value)->toBe(30)
        ->and($features->enabled('missing'))->toBeFalse()
        ->and($transport->features('org_b')->enabled('cortex.autoscaling'))->toBeFalse()
        ->and($transport->entitlements('org_a')->meters)->toBe([]);
});

it('rechecks revocations and account suspension on every read', function (): void {
    $transport = new FakeBillingTransport;
    $transport->feature('org_a', new FeatureEntitlement('cortex.autoscaling', FeatureType::Boolean, true, source: FeatureSource::Plan));
    expect($transport->features('org_a')->enabled('cortex.autoscaling'))->toBeTrue();
    $transport->suspendAccount('org_a');
    expect($transport->features('org_a')->enabled('cortex.autoscaling'))->toBeFalse();
    $transport->suspendAccount('org_a', false)->feature('org_a', new FeatureEntitlement('cortex.autoscaling', FeatureType::Boolean, false, source: FeatureSource::Override));
    expect($transport->features('org_a')->enabled('cortex.autoscaling'))->toBeFalse();
});

it('fails closed on malformed feature responses', function (array $body): void {
    Http::fake(['*' => Http::response($body)]);
    (new HttpBillingTransport(app(Factory::class), 'https://billing.test/api/v1', 'secret'))->features('org_a');
})->with([
    'missing features' => [['account' => ['suspended' => false]]],
    'missing account' => [['features' => []]],
    'string boolean' => [['features' => ['cortex.x' => ['type' => 'boolean', 'enabled' => 'false', 'value' => null, 'source' => 'plan']], 'account' => ['suspended' => false]]],
    'unsupported type' => [['features' => ['cortex.x' => ['type' => 'other', 'enabled' => true, 'value' => null, 'source' => 'plan']], 'account' => ['suspended' => false]]],
    'object value' => [['features' => ['cortex.x' => ['type' => 'config', 'enabled' => true, 'value' => [], 'source' => 'plan']], 'account' => ['suspended' => false]]],
])->throws(BillingUnavailable::class);

it('uses the scoped API path and refuses redirects without forwarding credentials', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://outside.test'])]);
    expect(fn () => (new HttpBillingTransport(app(Factory::class), 'https://billing.test/api/v1', 'secret'))->features('org/a'))->toThrow(BillingUnavailable::class);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://billing.test/api/v1/entitlements/org%2Fa/features' && $request->hasHeader('Authorization', 'Bearer secret'));
});

it('never turns a refused or unreachable feature API into an allowed grant', function (): void {
    $transport = new FakeBillingTransport;
    $transport->refuseWith(403);
    expect(fn () => $transport->features('org_a'))->toThrow(BillingRefused::class);
    $unavailable = new FakeBillingTransport;
    $unavailable->takeDown();
    expect(fn () => $unavailable->features('org_a'))->toThrow(BillingUnavailable::class);
});

it('keeps published and typed feature APIs on the same live organization snapshot', function (): void {
    $transport = (new FakeBillingTransport)->setFeatures('org_a', ['enabled' => true, 'disabled' => false, 'capacity' => 0, 'pool' => 'managed']);
    expect($transport)->toBeInstanceOf(FeatureReader::class)
        ->toBeInstanceOf(FeatureTransport::class);
    $snapshot = $transport->features('org_a');
    expect($snapshot)->toBeInstanceOf(OrganizationFeatures::class)
        ->and($snapshot->value('enabled'))->toBeTrue()
        ->and($snapshot->enabled('enabled'))->toBeTrue()
        ->and($snapshot->value('disabled'))->toBeNull()
        ->and($snapshot->enabled('disabled'))->toBeFalse()
        ->and($snapshot->value('capacity'))->toBe(0)
        ->and($snapshot->for('capacity')?->value)->toBe(0)
        ->and($snapshot->value('pool'))->toBe('managed');
    $transport->feature('org_a', new FeatureEntitlement('enabled', FeatureType::Boolean, false, source: FeatureSource::Override));
    expect($transport->features('org_a')->value('enabled'))->toBeNull()
        ->and($transport->features('org_a')->enabled('enabled'))->toBeFalse();
    $transport->setFeatures('org_a', ['replacement' => true], true);
    expect($transport->features('org_a')->value('replacement'))->toBeNull()
        ->and($transport->features('org_a')->enabled('replacement'))->toBeFalse();
    $transport->suspendAccount('org_a', false);
    expect($transport->features('org_a')->value('replacement'))->toBeTrue()
        ->and($transport->features('org_a')->value('pool'))->toBeNull()
        ->and($transport->features('org_b')->value('replacement'))->toBeNull();
});
