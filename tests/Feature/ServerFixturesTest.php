<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeBillingTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\Tests\Support\ServerFixture;
use Cbox\Billing\Client\ValueObjects\ActiveBundle;
use Cbox\Billing\Client\ValueObjects\BundleBenefit;
use Cbox\Billing\Client\ValueObjects\BundleConsequence;
use Cbox\Billing\Client\ValueObjects\BundleOffer;
use Cbox\Billing\Client\ValueObjects\BundleOfferPlan;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\MeterActual;
use Cbox\Billing\Client\ValueObjects\MeterEstimate;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\OrganizationBundles;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\PendingChange;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionBundle;
use Cbox\Billing\Client\ValueObjects\SubscriptionDiscount;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * INT-1: fake and real must not drift. `tests/Fixtures/server/*.json` records what the Cbox
 * Billing controllers emit (each names its source). Two halves hold to them:
 *
 *  1. the REAL transports parse every fixture, so a parser that reads a key the server does
 *     not send fails here;
 *  2. the FAKES answer every endpoint in exactly the fixture's shape, so a fake that invents
 *     or drops a field fails here.
 *
 * The contract suite (`tests/Contract`) closes the loop by holding a live sandbox server to
 * the same fixtures.
 */
/** A fresh HTTP client that answers every request with the recorded fixture. */
function replaying(string $fixture): HttpFactory
{
    $recorded = ServerFixture::load($fixture);

    return (new HttpFactory)->fake(['*' => HttpFactory::response($recorded->body, $recorded->status)]);
}

function enforcementReplaying(string $fixture): HttpBillingTransport
{
    return new HttpBillingTransport(replaying($fixture), 'https://billing.test', 'token');
}

function managementReplaying(string $fixture): HttpManagementTransport
{
    return new HttpManagementTransport(replaying($fixture), 'https://billing.test', 'token');
}

it('parses every recorded enforcement response', function (): void {
    $grant = enforcementReplaying('lease')->lease('org_a', 'api.calls', 100);
    expect($grant->granted)->toBe(100)
        ->and($grant->expiresAt)->toBe((new DateTimeImmutable('2026-10-28T12:05:00+00:00'))->getTimestamp() * 1000);

    $entitlements = enforcementReplaying('entitlements')->entitlements('org_a');
    expect($entitlements->for('api.calls')?->allowance)->toBe(1_000)
        ->and($entitlements->for('seats')?->isUnlimited())->toBeTrue()
        ->and($entitlements->enabled('storage.gb'))->toBeFalse();
});

it('parses the recorded reserve, commit and usage responses', function (): void {
    expect(enforcementReplaying('reserve-allowed')->reserve('org_a', [new MeterEstimate('api.calls', 1)])->allowed())->toBeTrue();

    expect(enforcementReplaying('reserve-denied')->reserve('org_a', [new MeterEstimate('api.calls', 1)])->reason)->toBe('disabled_meter');

    enforcementReplaying('commit')->commit('res_1', [new MeterActual('api.calls', 1)]);

    enforcementReplaying('usage-ingest')->reportUsage('org_a', [new CumulativeUsage('org_a', 'api.calls', 1, 1)]);
});

it('parses every recorded management response', function (): void {
    expect(managementReplaying('plans')->plans()[0]->priceMinor)->toBe(4_900);

    expect(managementReplaying('subscription')->subscription('org_a')?->plan)->toBe('pro');

    expect(managementReplaying('subscription')->subscription('org_a')?->discount?->percentOff)->toBe(20);

    expect(managementReplaying('reactivate')->reactivate('org_r')->cancelsAtPeriodEnd())->toBeFalse();

    $subscription = managementReplaying('subscription')->subscription('org_a');
    expect($subscription?->bundle?->benefit?->label)->toBe('Included with Cbox Billing')
        ->and($subscription?->bundle?->benefit?->isFree())->toBeTrue()
        ->and($subscription?->bundle?->benefit?->requires)->toBe('cbox-billing')
        ->and($subscription?->bundle?->isEnding())->toBeTrue()
        ->and($subscription?->pendingChange?->plan)->toBe('starter')
        ->and($subscription?->pendingChange?->effectiveAt?->format('Y-m-d'))->toBe('2026-11-01')
        ->and($subscription?->trialEndsAt)->toBeNull();

    $feedRow = managementReplaying('subscriptions')->subscriptions()->data[0];
    expect($feedRow->reference)->toBeNull()
        ->and($feedRow->bundle?->benefit?->percentOff)->toBe(100);

    $cancelPreview = managementReplaying('cancel-preview')->previewCancel('org_a');
    expect($cancelPreview->mode)->toBe('period_end')
        ->and($cancelPreview->hasConsequences())->toBeTrue()
        ->and($cancelPreview->consequences[0]->lost)->toBeTrue()
        ->and($cancelPreview->consequences[0]->priceAfterMinor)->toBe(21_900)
        ->and($cancelPreview->consequences[0]->before?->rule)->toBe('billing-includes-tax');

    expect(managementReplaying('preview')->previewChange('org_a', 'pro')->consequences[0]->product)->toBe('cbox-tax');

    $bundles = managementReplaying('bundles')->bundles('org_a');
    expect($bundles->activeFor('cbox-tax')?->bundle->benefit?->label)->toBe('Included with Cbox Billing')
        ->and($bundles->offersOfKind('add_product')[0]->plans[0]->isFree())->toBeTrue()
        ->and($bundles->offersOfKind('add_product')[0]->plans[0]->checkoutUrl)->toContain('/billing/upgrade')
        ->and($bundles->offersOfKind('multi_product')[0]->productsToAdd)->toBe(['cbox-sites' => 'Cbox Sites'])
        ->and($bundles->offersOfKind('multi_product')[0]->productsNeeded)->toBe(1);

    expect(fn () => managementReplaying('reactivate-conflict')->reactivate('org_a'))->toThrow(BillingRefused::class);

    expect(managementReplaying('subscriptions')->subscriptions()->data[0]->organizationRef)->toBe('org_a');

    expect(managementReplaying('subscription-missing')->subscription('org_new'))->toBeNull();

    expect(managementReplaying('subscribe')->subscribe('org_a', 'pro')->paymentIntent)->toBeNull();

    expect(managementReplaying('preview')->previewChange('org_a', 'pro')->lines[1]->amountMinor)->toBe(-2_500);

    expect(managementReplaying('change')->changePlan('org_a', 'pro')->dueNowMinor)->toBe(2_400);

    expect(managementReplaying('cancel')->cancel('org_a', true)->cancelsAtPeriodEnd())->toBeTrue();

    expect(managementReplaying('usage-summary')->usage('org_a')->for('seats')?->allowance)->toBeNull();

    expect(managementReplaying('invoices')->invoices('org_a')[0]->number)->toBe('CB-2026-0042');

    expect(managementReplaying('checkout-session')->createCheckoutSession('org_a', 'pro', 'https://app.test')->expiresAt)->not->toBeNull();

    expect(managementReplaying('portal-session')->createPortalSession('org_a', 'https://app.test')->url)->toContain('/portal/');

    expect(managementReplaying('setup-intent')->createSetupIntent('org_a')->clientSecret)->toBe('seti_1_secret_2');

    expect(managementReplaying('payment-intent')->createPaymentIntent('org_a', amountMinor: 4_900, currency: 'EUR')->reference)->toBe('pi_1');

    expect(managementReplaying('payment-methods')->paymentMethods('org_a')[0]->last4)->toBe('4242');

    managementReplaying('payment-method-default')->setDefaultPaymentMethod('org_a', 'pm_1');

    managementReplaying('organization-upsert')->ensureOrganization('org_a', ['name' => 'Acme']);
});

it('reads the recorded 401 as a refusal', function (): void {
    expect(fn () => managementReplaying('unauthenticated')->plans())->toThrow(BillingRefused::class);
});

function fixtureBenefit(?string $requires = 'cbox-billing'): BundleBenefit
{
    return new BundleBenefit('billing-includes-tax', 'product', 'Included with Cbox Billing', 21_900, 0, 'DKK', percentOff: 100, requires: $requires);
}

function fixtureBundle(): SubscriptionBundle
{
    return new SubscriptionBundle(fixtureBenefit(), new DateTimeImmutable('2026-11-15T00:00:00+00:00'), null, new DateTimeImmutable('2026-11-15T00:00:00+00:00'));
}

function fixtureConsequence(): BundleConsequence
{
    return new BundleConsequence(
        lost: true, from: new DateTimeImmutable('2026-11-15T00:00:00+00:00'), message: 'Cbox Tax is no longer included from 15 Nov 2026.',
        priceBeforeMinor: 0, priceAfterMinor: 21_900, currency: 'DKK', subscription: 'sub_3b5d7f9h1j3l5n7p9r1t3v5x',
        product: 'cbox-tax', productName: 'Cbox Tax', plan: 'tax-starter', before: fixtureBenefit(),
    );
}

function seededManagementFake(): FakeManagementTransport
{
    $start = new DateTimeImmutable('2026-10-01T00:00:00+00:00');

    return (new FakeManagementTransport)
        ->withPlan(new Plan('starter', 'Starter', 2_500, 'EUR', 'month', [new Entitlement('api.calls', true, 10_000, 1.0, 'block')]))
        ->withPlan(new Plan('pro', 'Pro', 4_900, 'EUR', 'month', [new Entitlement('api.calls', true, 100_000, 1.0, 'bill')]))
        ->withSubscription('org_a', new Subscription(
            'starter', 'active', $start, $start->modify('+1 month'), $start->modify('+1 month'),
            discount: new SubscriptionDiscount('SAVE20', 'repeating', 'Launch offer', 20, endsAt: new DateTimeImmutable('2027-01-01T00:00:00+00:00')),
            pendingChange: new PendingChange('starter', $start->modify('+1 month')),
            bundle: fixtureBundle(),
        ), $start->modify('+27 days'))
        ->withConsequences('org_a', fixtureConsequence())
        ->withBundles('org_a', new OrganizationBundles(
            organization: '01JBX7Q3W9T0S2M4V6Y8Z1A3C5',
            currency: 'DKK',
            locale: 'en',
            active: [new ActiveBundle(fixtureBundle(), 'sub_3b5d7f9h1j3l5n7p9r1t3v5x', 'cbox-tax', 'tax-starter')],
            offers: [new BundleOffer(
                rule: 'billing-includes-tax', kind: 'add_product', headline: 'Add Cbox Tax, free with your Billing plan',
                label: 'Included with Cbox Billing', percentOff: 100, product: 'cbox-tax', productName: 'Cbox Tax', discountedProduct: 'cbox-tax',
                plans: [new BundleOfferPlan('tax-starter', 'Starter', 21_900, 0, 'DKK', 'https://billing.test/billing/upgrade?plan=tax-starter')],
            )],
            organizationRef: 'org_a',
        ))
        // A period-end cancel, so reactivate has something to undo.
        ->withSubscription('org_r', new Subscription('starter', 'active', $start, $start->modify('+1 month'), null), $start->modify('+28 days'))
        ->withUsage('org_a', 'api.calls', new MeterUsage(750, 1_000, 0))
        ->withInvoice('org_a', new Invoice('CB-1', $start, 2_500, 'EUR', 'paid'))
        ->withPaymentMethod('org_a', new PaymentMethod('pm_1', 'visa', '4242', 12, 2030, true));
}

/**
 * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
 */
dataset('management endpoints', [
    'plans' => ['plans', 'GET', '/api/v1/plans', []],
    'subscription' => ['subscription', 'GET', '/api/v1/subscriptions/org_a', []],
    'subscription missing' => ['subscription-missing', 'GET', '/api/v1/subscriptions/org_new', []],
    'subscribe' => ['subscribe', 'POST', '/api/v1/subscriptions', ['org' => 'org_b', 'plan' => 'pro']],
    'preview' => ['preview', 'POST', '/api/v1/subscriptions/org_a/preview', ['plan' => 'pro']],
    'change' => ['change', 'POST', '/api/v1/subscriptions/org_a/change', ['plan' => 'pro']],
    'cancel' => ['cancel', 'POST', '/api/v1/subscriptions/org_a/cancel', []],
    'reactivate' => ['reactivate', 'POST', '/api/v1/subscriptions/org_r/reactivate', []],
    'cancel preview' => ['cancel-preview', 'POST', '/api/v1/subscriptions/org_a/cancel/preview', []],
    'bundles' => ['bundles', 'GET', '/api/v1/organizations/org_a/bundles', []],
    'reactivate conflict' => ['reactivate-conflict', 'POST', '/api/v1/subscriptions/org_a/reactivate', []],
    'usage' => ['usage-summary', 'GET', '/api/v1/usage/org_a', []],
    'invoices' => ['invoices', 'GET', '/api/v1/invoices/org_a', []],
    'checkout' => ['checkout-session', 'POST', '/api/v1/checkout-sessions', ['org' => 'org_a', 'plan' => 'pro', 'return_url' => 'https://app.test']],
    'portal' => ['portal-session', 'POST', '/api/v1/portal-sessions', ['org' => 'org_a', 'return_url' => 'https://app.test']],
    'setup intent' => ['setup-intent', 'POST', '/api/v1/setup-intents', ['org' => 'org_a']],
    'payment intent' => ['payment-intent', 'POST', '/api/v1/payment-intents', ['org' => 'org_a', 'amount' => 4_900, 'currency' => 'EUR']],
    'payment methods' => ['payment-methods', 'GET', '/api/v1/payment-methods/org_a', []],
    'default payment method' => ['payment-method-default', 'POST', '/api/v1/payment-methods/org_a/default', ['id' => 'pm_1']],
    'organization' => ['organization-upsert', 'PUT', '/api/v1/organizations/org_c', ['name' => 'Acme']],
]);

it('answers in the recorded shape: management', function (string $fixture, string $method, string $path, array $payload): void {
    $recorded = ServerFixture::load($fixture);
    $answer = seededManagementFake()->respond($method, $path, $payload);

    expect($answer->status)->toBe($recorded->status)
        ->and(ServerFixture::shape($answer->body))->toBe(ServerFixture::shape($recorded->body));
})->with('management endpoints');

it('answers the change feed in the recorded shape', function (): void {
    $recorded = ServerFixture::load('subscriptions');
    $answer = seededManagementFake()->respond('GET', '/api/v1/subscriptions', [], ['limit' => '1']);

    expect($answer->status)->toBe($recorded->status)
        ->and(ServerFixture::shape($answer->body))->toBe(ServerFixture::shape($recorded->body));
});

function seededBillingFake(): FakeBillingTransport
{
    return (new FakeBillingTransport)
        ->grant('org_a', 'api.calls', 1_000)
        ->entitlement('org_a', new Entitlement('api.calls', true, 1_000, 1.5, 'block'))
        ->entitlement('org_a', new Entitlement('seats', true, null, 1.0, 'bill'))
        ->entitlement('org_a', new Entitlement('storage.gb', false, null, 1.0, 'block'));
}

it('answers in the recorded shape: enforcement', function (): void {
    $fake = seededBillingFake();

    $answers = [
        'lease' => $fake->respond('POST', '/api/v1/leases', ['org' => 'org_a', 'meter' => 'api.calls', 'size' => 100]),
        'usage-ingest' => $fake->respond('POST', '/api/v1/usage', ['org' => 'org_a', 'entries' => [['meter' => 'api.calls', 'cumulative' => 5, 'seq' => 1]]]),
        'reserve-allowed' => $fake->respond('POST', '/api/v1/reserve', ['org' => 'org_a', 'meters' => [['meter' => 'api.calls', 'estimate' => 1]]]),
        'reserve-denied' => $fake->respond('POST', '/api/v1/reserve', ['org' => 'org_a', 'meters' => [['meter' => 'storage.gb', 'estimate' => 1]]]),
        'commit' => $fake->respond('POST', '/api/v1/commit', ['reservation_id' => 'res_1', 'actuals' => [['meter' => 'api.calls', 'actual' => 1]]]),
        'entitlements' => $fake->respond('GET', '/api/v1/entitlements/org_a'),
    ];

    foreach ($answers as $fixture => $answer) {
        $recorded = ServerFixture::load($fixture);

        expect($answer->status)->toBe($recorded->status, $fixture)
            ->and(ServerFixture::shape($answer->body))->toBe(ServerFixture::shape($recorded->body), $fixture);
    }
});

it('reports a refusal from the fake the way the server sends it', function (): void {
    $management = new BillingManagement(seededManagementFake()->refuseWith(401));

    expect(fn () => $management->plans())->toThrow(BillingRefused::class);
});
