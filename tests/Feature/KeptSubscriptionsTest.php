<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Enums\TaxBasis;
use Cbox\Billing\Client\Enums\TaxStatus;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\SubscriptionPayload;
use Cbox\Billing\Client\Jobs\RefreshKeptAnswer;
use Cbox\Billing\Client\Kept\KeptSubscriptions;
use Cbox\Billing\Client\Kept\SubscriptionQuestion;
use Cbox\Billing\Client\ValueObjects\BundleBenefit;
use Cbox\Billing\Client\ValueObjects\PendingChange;
use Cbox\Billing\Client\ValueObjects\PriceTax;
use Cbox\Billing\Client\ValueObjects\RecurringPrice;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionBundle;
use Cbox\Billing\Client\ValueObjects\SubscriptionDiscount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config(['billing-client.cache_store' => 'array']);
    $this->app->instance(ManagementTransport::class, $this->managementTransport());
});

function richSubscription(): Subscription
{
    return new Subscription(
        plan: 'pro',
        status: 'active',
        periodStart: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        periodEnd: new DateTimeImmutable('2026-12-01T00:00:00+00:00'),
        renewsAt: new DateTimeImmutable('2026-12-01T00:00:00+00:00'),
        discount: new SubscriptionDiscount('LAUNCH', 'repeating', 'Launch', 20, null, null, new DateTimeImmutable('2027-01-01T00:00:00+00:00')),
        organizationRef: 'org_1',
        pauseReason: null,
        paused: false,
        trialEndsAt: null,
        pendingChange: new PendingChange('team', new DateTimeImmutable('2026-12-01T00:00:00+00:00')),
        bundle: new SubscriptionBundle(new BundleBenefit('billing-includes-tax', 'product', 'Included', 21_900, 0, 'DKK', percentOff: 100), new DateTimeImmutable('2026-12-15T00:00:00+00:00')),
        recurring: new RecurringPrice(9_900, 'DKK', new PriceTax(TaxStatus::Calculated, TaxBasis::Customer, 'DK', 'business', 'standard', '25.00', 2_475, 12_375)),
    );
}

function keptSubscriptions(): KeptSubscriptions
{
    return app(KeptSubscriptions::class);
}

it('writes a subscription in the wire shape and reads it back unchanged', function (): void {
    $subscription = richSubscription();

    expect(SubscriptionPayload::subscription(SubscriptionPayload::toArray($subscription), 'test'))->toEqual($subscription)
        ->and(SubscriptionPayload::toArray($subscription))->toBe(json_decode(json_encode(SubscriptionPayload::toArray($subscription), JSON_THROW_ON_ERROR), true));
});

it('reads the subscription once and answers from the kept copy while it is fresh', function (): void {
    $this->managementTransport()->withSubscription('org_1', new Subscription('pro', 'active'));

    $first = keptSubscriptions()->subscription('org_1');
    $this->managementTransport()->takeDown();

    expect($first?->plan)->toBe('pro')
        ->and(keptSubscriptions()->subscription('org_1')?->plan)->toBe('pro');
});

it('keeps a richer subscription through the cache store intact', function (): void {
    keptSubscriptions()->remember('org_1', richSubscription());

    expect(keptSubscriptions()->subscription('org_1'))->toEqual(richSubscription());
});

it('keeps "no subscription" as an answer too', function (): void {
    expect(keptSubscriptions()->subscription('org_new'))->toBeNull();

    $this->managementTransport()->takeDown();

    expect(keptSubscriptions()->subscription('org_new'))->toBeNull();
});

it('serves the kept subscription on a page while Billing is down, and refreshes it in the background', function (): void {
    Bus::fake();
    $this->managementTransport()->withSubscription('org_1', new Subscription('pro', 'active'));
    keptSubscriptions()->subscription('org_1');
    $this->travel(31)->seconds();
    $this->managementTransport()->takeDown();

    expect(keptSubscriptions()->subscription('org_1')?->plan)->toBe('pro');
    Bus::assertDispatched(RefreshKeptAnswer::class, fn (RefreshKeptAnswer $job): bool => $job->question == new SubscriptionQuestion('org_1'));

    $this->travel(3)->days();

    expect(keptSubscriptions()->subscription('org_1')?->plan)->toBe('pro');
});

it('reads it live before a change', function (): void {
    $this->managementTransport()->withSubscription('org_1', new Subscription('pro', 'active'));
    keptSubscriptions()->subscription('org_1');
    $this->travel(31)->seconds();
    $this->managementTransport()->withSubscription('org_1', new Subscription('team', 'active'));

    expect(keptSubscriptions()->subscription('org_1', acting: true)?->plan)->toBe('team');

    $this->travel(31)->seconds();
    $this->managementTransport()->takeDown();
    app()->instance('request', Request::create('/billing/change', 'POST'));

    expect(fn () => keptSubscriptions()->subscription('org_1'))->toThrow(BillingUnavailable::class);
});

it('forgets the kept subscription when the webhook says it changed', function (): void {
    $this->managementTransport()->withSubscription('org_1', new Subscription('pro', 'active'));
    keptSubscriptions()->subscription('org_1');
    $this->managementTransport()->withSubscription('org_1', new Subscription('team', 'active'));

    expect(keptSubscriptions()->subscription('org_1')?->plan)->toBe('pro');

    keptSubscriptions()->forget('org_1');

    expect(keptSubscriptions()->subscription('org_1')?->plan)->toBe('team')
        ->and(keptSubscriptions()->refresh('org_1')?->plan)->toBe('team');
});

it('reads a kept copy it cannot parse as nothing kept', function (): void {
    $question = new SubscriptionQuestion('org_1');

    expect($question->accepts(['subscription' => ['status' => 'active']]))->toBeFalse()
        ->and($question->accepts(['subscription' => null]))->toBeTrue()
        ->and($question->accepts(['plan' => 'pro']))->toBeFalse()
        ->and(SubscriptionQuestion::parse('pro'))->toBeNull();
});
