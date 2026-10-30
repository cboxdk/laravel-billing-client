<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\ActiveBundle;
use Cbox\Billing\Client\ValueObjects\BundleBenefit;
use Cbox\Billing\Client\ValueObjects\BundleConsequence;
use Cbox\Billing\Client\ValueObjects\BundleOffer;
use Cbox\Billing\Client\ValueObjects\OrganizationBundles;
use Cbox\Billing\Client\ValueObjects\PendingChange;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionBundle;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/*
 * 0.4.3: cross-product bundles. The `bundle` block, the trial end and a scheduled plan change on a
 * subscription, the bundles an app shows, and the cancel preview with what the organization's
 * other subscriptions lose. Every new field is optional, so an older server still parses.
 */

function taxIncluded(): BundleBenefit
{
    return new BundleBenefit('billing-includes-tax', 'product', 'Included with Cbox Billing', 21_900, 0, 'DKK', percentOff: 100, text: '100% off', requires: 'cbox-billing');
}

it('carries the bundle, the trial end and a scheduled change on a subscription', function (): void {
    $start = new DateTimeImmutable('2026-10-15T00:00:00+00:00');
    $fake = (new FakeManagementTransport)->withSubscription('org_a', new Subscription(
        'tax-starter', 'trialing', $start, $start->modify('+1 month'), $start->modify('+1 month'),
        trialEndsAt: $start->modify('+14 days'),
        pendingChange: new PendingChange('tax-growth', $start->modify('+1 month')),
        bundle: new SubscriptionBundle(taxIncluded()),
    ));

    $subscription = (new BillingManagement($fake))->subscription('org_a');

    expect($subscription?->isTrialing())->toBeTrue()
        ->and($subscription?->trialEndsAt?->format('Y-m-d'))->toBe('2026-10-29')
        ->and($subscription?->pendingChange?->plan)->toBe('tax-growth')
        ->and($subscription?->bundle?->benefit?->label)->toBe('Included with Cbox Billing')
        ->and($subscription?->bundle?->benefit?->isFree())->toBeTrue()
        ->and($subscription?->bundle?->isEnding())->toBeFalse();

    $row = (new BillingManagement($fake))->subscriptions()->data[0];
    expect($row->bundle?->benefit?->rule)->toBe('billing-includes-tax')
        ->and($row->reference)->toBeNull();
});

it('reads the bundles an app shows, and none when nothing is seeded', function (): void {
    $fake = (new FakeManagementTransport)->withBundles('org_a', new OrganizationBundles(
        organization: 'org_a', currency: 'DKK', locale: 'da',
        active: [new ActiveBundle(new SubscriptionBundle(taxIncluded()), 'sub_1', 'cbox-tax', 'tax-starter')],
        offers: [new BundleOffer('multi-3', 'multi_product', 'Hav 3 betalte Cbox-produkter og spar 15 % på hvert', percentOff: 15, minPaidProducts: 3, paidProductsHeld: 2, productsNeeded: 1, currentPercentOff: 10, productsToAdd: ['cbox-sites' => 'Cbox Sites'])],
    ));
    $management = new BillingManagement($fake);

    $bundles = $management->bundles('org_a');
    expect($bundles->activeFor('cbox-tax')?->bundle->benefit?->percentOff)->toBe(100)
        ->and($bundles->activeFor('cbox-telemetry'))->toBeNull()
        ->and($bundles->offersOfKind('multi_product')[0]->productsToAdd)->toBe(['cbox-sites' => 'Cbox Sites'])
        ->and($bundles->offersOfKind('add_product'))->toBe([]);

    $none = $management->bundles('org_b', 'cbox-tax', 'en');
    expect($none->active)->toBe([])
        ->and($none->offers)->toBe([])
        ->and($none->product)->toBe('cbox-tax');
});

it('previews a cancel with the consequences, changing nothing', function (): void {
    $start = new DateTimeImmutable('2026-10-01T00:00:00+00:00');
    $fake = (new FakeManagementTransport)
        ->withSubscription('org_a', new Subscription('billing-growth', 'active', $start, $start->modify('+1 month'), $start->modify('+1 month')))
        ->withConsequences('org_a', new BundleConsequence(true, new DateTimeImmutable('2026-11-15T00:00:00+00:00'), 'Cbox Tax is no longer included from 15 Nov 2026.', 0, 21_900, 'DKK', product: 'cbox-tax', before: taxIncluded()));
    $management = new BillingManagement($fake);

    $preview = $management->previewCancel('org_a');
    expect($preview->hasConsequences())->toBeTrue()
        ->and($preview->mode)->toBe('period_end')
        ->and($preview->endsAt?->format('Y-m-d'))->toBe('2026-11-01')
        ->and($preview->consequences[0]->message)->toBe('Cbox Tax is no longer included from 15 Nov 2026.')
        ->and($preview->consequences[0]->priceAfterMinor)->toBe(21_900);

    expect($management->previewCancel('org_a', atPeriodEnd: false)->mode)->toBe('immediate')
        ->and($management->subscription('org_a')?->cancelsAtPeriodEnd())->toBeFalse('a preview changes nothing');

    expect(fn () => $management->previewCancel('org_none'))->toThrow(BillingRefused::class);
});

it('sends the product and locale to the bundles endpoint and the mode to the cancel preview', function (): void {
    $sent = [];
    $http = (new HttpFactory)->fake(function (Request $request) use (&$sent) {
        $sent[] = $request->method().' '.$request->url().' '.json_encode($request->data());

        return str_contains($request->url(), '/bundles')
            ? HttpFactory::response(['organization' => 'org_a', 'currency' => 'EUR', 'locale' => 'da', 'active' => [], 'offers' => []])
            : HttpFactory::response(['mode' => 'immediate', 'ends_at' => null, 'consequences' => []]);
    });
    $transport = new HttpManagementTransport($http, 'https://billing.test', 'token');

    $transport->bundles('org a', 'cbox-tax', 'da');
    $transport->previewCancel('org_a', false, 'da');

    expect($sent)->toBe([
        'GET https://billing.test/api/v1/organizations/org%20a/bundles?product=cbox-tax&locale=da {"product":"cbox-tax","locale":"da"}',
        'POST https://billing.test/api/v1/subscriptions/org_a/cancel/preview {"mode":"immediate","locale":"da"}',
    ]);
});

it('refuses a bundles answer without its lists as malformed', function (): void {
    $http = (new HttpFactory)->fake(['*' => HttpFactory::response(['organization' => 'org_a', 'currency' => 'EUR', 'locale' => 'en'])]);

    expect(fn () => (new HttpManagementTransport($http, 'https://billing.test', 'token'))->bundles('org_a'))
        ->toThrow(TransportException::class, 'missing active array');
});

it('reads an older server that sends no bundle fields', function (): void {
    $http = (new HttpFactory)->fake(['*' => HttpFactory::response(['plan' => 'pro', 'status' => 'active'])]);
    $subscription = (new HttpManagementTransport($http, 'https://billing.test', 'token'))->subscription('org_a');

    expect($subscription?->bundle)->toBeNull()
        ->and($subscription?->pendingChange)->toBeNull()
        ->and($subscription?->trialEndsAt)->toBeNull();
});
