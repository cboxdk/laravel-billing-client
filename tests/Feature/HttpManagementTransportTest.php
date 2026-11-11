<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\UsageGroup;
use Cbox\Billing\Client\ValueObjects\UsagePrice;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeHttpManagement(): HttpManagementTransport
{
    return new HttpManagementTransport(
        app(HttpFactory::class),
        'https://billing.test/api/v1',
        'secret-token',
    );
}

it('parses plan features, product and tax convention, and tolerates a server without them', function (): void {
    Http::fake([
        'billing.test/api/v1/plans' => Http::response([
            'currency' => 'DKK',
            'data' => [
                ['key' => 'sites-pro', 'name' => 'Pro', 'interval' => 'month', 'product' => 'cbox-sites',
                    'entitlements' => [],
                    'features' => ['sites.pages' => 250, 'sites.whitelabel' => true, 'sites.theme' => 'dark', 'sites.forms' => false, 'bad' => 1.5, 'nested' => ['x' => 1]],
                    'price' => ['minor' => 9_900, 'currency' => 'DKK', 'tax_inclusive' => false]],
                ['key' => 'legacy', 'name' => 'Legacy', 'interval' => 'month', 'entitlements' => [],
                    'price' => ['minor' => 100, 'currency' => 'DKK']],
            ],
            'has_more' => false, 'next_cursor' => null,
        ]),
    ]);

    [$pro, $legacy] = makeHttpManagement()->plans();

    expect($pro->features)->toBe(['sites.forms' => false, 'sites.pages' => 250, 'sites.theme' => 'dark', 'sites.whitelabel' => true])
        ->and($pro->product)->toBe('cbox-sites')
        ->and($pro->taxInclusive)->toBeFalse()
        ->and($legacy->features)->toBe([])
        ->and($legacy->product)->toBeNull()
        ->and($legacy->taxInclusive)->toBeNull();
});

it('lists plans over HTTP with a bearer token', function (): void {
    Http::fake([
        'billing.test/api/v1/plans' => Http::response([
            'plans' => [
                [
                    'key' => 'pro',
                    'name' => 'Pro',
                    'price_minor' => 4_900,
                    'currency' => 'usd',
                    'interval' => 'month',
                    'entitlements' => [
                        'api.calls' => ['enabled' => true, 'allowance' => 100_000, 'weight' => 1.0, 'overage' => 'bill'],
                    ],
                ],
            ],
        ]),
    ]);

    $plans = makeHttpManagement()->plans();

    expect($plans)->toHaveCount(1)
        ->and($plans[0]->key)->toBe('pro')
        ->and($plans[0]->priceMinor)->toBe(4_900)
        ->and($plans[0]->entitlement('api.calls')?->overage)->toBe('bill');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->url() === 'https://billing.test/api/v1/plans');
});

it('reads the current subscription and parses its dates', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a' => Http::response([
            'subscription' => [
                'plan' => 'pro',
                'status' => 'active',
                'period_start' => '2026-07-01T00:00:00+00:00',
                'period_end' => '2026-08-01T00:00:00+00:00',
                'renews_at' => '2026-08-01T00:00:00+00:00',
            ],
        ]),
    ]);

    $subscription = makeHttpManagement()->subscription('org_a');

    expect($subscription?->plan)->toBe('pro')
        ->and($subscription?->periodEnd?->format('Y-m-d'))->toBe('2026-08-01')
        ->and($subscription?->cancelsAtPeriodEnd())->toBeFalse();
});

it('subscribes and returns a payment intent', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions' => Http::response([
            'subscription' => ['plan' => 'pro', 'status' => 'incomplete'],
            'payment_intent' => ['id' => 'pi_1', 'status' => 'requires_confirmation', 'client_secret' => 'cs_1'],
        ]),
    ]);

    $result = makeHttpManagement()->subscribe('org_a', 'pro');

    expect($result->subscription->plan)->toBe('pro')
        ->and($result->requiresPayment())->toBeTrue()
        ->and($result->paymentIntent?->clientSecret)->toBe('cs_1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/subscriptions'
        && $request['org'] === 'org_a'
        && $request['plan'] === 'pro');
});

it('previews a plan change with itemized lines', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a/preview' => Http::response([
            'due_now_minor' => 2_400,
            'credit_minor' => 2_500,
            'new_recurring_minor' => 4_900,
            'effective_at' => '2026-07-16T12:00:00+00:00',
            'lines' => [
                // The server emits `minor` on a proration line. These fixtures used
                // `amount_minor` — the SDK's own assumption — so the suite stayed green while the
                // real transport threw on every prorated change.
                ['description' => 'Pro plan', 'minor' => 4_900],
                ['description' => 'Unused credit', 'minor' => -2_500],
            ],
        ]),
    ]);

    $preview = makeHttpManagement()->previewChange('org_a', 'pro');

    expect($preview->dueNowMinor)->toBe(2_400)
        ->and($preview->creditMinor)->toBe(2_500)
        ->and($preview->lines)->toHaveCount(2)
        ->and($preview->lines[1]->amountMinor)->toBe(-2_500)
        ->and($preview->effectiveAt?->format('H:i'))->toBe('12:00');
});

it('cancels at period end', function (): void {
    Http::fake([
        // The server returns the subscription UNWRAPPED from /cancel.
        'billing.test/api/v1/subscriptions/org_a/cancel' => Http::response(
            ['plan' => 'pro', 'status' => 'active', 'renews_at' => null],
        ),
    ]);

    $subscription = makeHttpManagement()->cancel('org_a', true);

    expect($subscription->cancelsAtPeriodEnd())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/subscriptions/org_a/cancel'
        && $request['at_period_end'] === true);
});

it('reads usage per meter with the period', function (): void {
    Http::fake([
        'billing.test/api/v1/usage/org_a' => Http::response([
            'meters' => [
                'api.calls' => ['used' => 750, 'allowance' => 1_000, 'overage' => 0],
            ],
            'period' => ['start' => '2026-07-01T00:00:00+00:00', 'end' => '2026-08-01T00:00:00+00:00'],
        ]),
    ]);

    $usage = makeHttpManagement()->usage('org_a');

    expect($usage->for('api.calls')?->used)->toBe(750)
        ->and($usage->for('api.calls')?->remaining())->toBe(250)
        ->and($usage->period->start?->format('Y-m-d'))->toBe('2026-07-01');
});

it('lists invoices over HTTP', function (): void {
    Http::fake([
        'billing.test/api/v1/invoices/org_a' => Http::response([
            'invoices' => [
                ['number' => 'INV-1', 'date' => '2026-07-01', 'amount_minor' => 4_900, 'currency' => 'usd', 'status' => 'paid'],
            ],
        ]),
    ]);

    $invoices = makeHttpManagement()->invoices('org_a');

    expect($invoices)->toHaveCount(1)
        ->and($invoices[0]->number)->toBe('INV-1')
        ->and($invoices[0]->isPaid())->toBeTrue();
});

it('raises a transport exception on a non-2xx response (deny-by-default)', function (): void {
    Http::fake(['billing.test/api/v1/plans' => Http::response([], 500)]);

    expect(fn () => makeHttpManagement()->plans())->toThrow(TransportException::class);
});

it('raises a transport exception on a malformed body', function (): void {
    Http::fake(['billing.test/api/v1/plans' => Http::response('not-json', 200)]);

    expect(fn () => makeHttpManagement()->plans())->toThrow(TransportException::class);
});

it('provisions an organization with an idempotent PUT', function (): void {
    Http::fake([
        'billing.test/api/v1/organizations/org_1' => Http::response(['organization' => ['id' => 'org_1']], 201),
    ]);

    makeHttpManagement()->ensureOrganization('org_1', [
        'name' => 'Acme',
        'billing_email' => 'billing@acme.test',
        'billing_currency' => 'USD',
    ]);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/api/v1/organizations/org_1')
        && $request['name'] === 'Acme'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('sends the purchaser type, EAN and order reference on the organization upsert', function (): void {
    Http::fake([
        'billing.test/api/v1/organizations/org_1' => Http::response(['organization' => ['id' => 'org_1']]),
    ]);

    makeHttpManagement()->ensureOrganization('org_1', [
        'name' => 'Kommune',
        'purchaser_type' => 'public_body',
        'ean_number' => '5790000000005',
        'order_reference' => 'Rekv. 4711',
    ]);

    Http::assertSent(fn ($request): bool => $request['purchaser_type'] === 'public_body'
        && $request['ean_number'] === '5790000000005'
        && $request['order_reference'] === 'Rekv. 4711');
});

it('surfaces an organization provisioning failure as a transport exception', function (): void {
    Http::fake([
        'billing.test/api/v1/organizations/org_1' => Http::response(['error' => 'nope'], 403),
    ]);

    makeHttpManagement()->ensureOrganization('org_1', ['name' => 'Acme']);
})->throws(TransportException::class);

it('parses the nested price shape the live catalog endpoint returns', function (): void {
    Http::fake([
        'billing.test/api/v1/plans' => Http::response([
            'currency' => 'USD',
            'data' => [
                [
                    'key' => 'growth', 'name' => 'Growth', 'interval' => 'month',
                    'entitlements' => ['conversations' => ['enabled' => true, 'allowance' => 10_000, 'weight' => 0.03, 'overage' => 'bill']],
                    'price' => ['minor' => 19_900, 'currency' => 'USD'],
                ],
            ],
        ]),
    ]);

    $plans = makeHttpManagement()->plans();

    expect($plans[0]->priceMinor)->toBe(19_900)
        ->and($plans[0]->currency)->toBe('USD')
        ->and($plans[0]->entitlement('conversations')?->weight)->toBe(0.03);
});

/**
 * INT-1: the server answers 404 for an org with no subscription
 * (`SubscriptionController::show`). The transport threw, so a brand-new organization could not
 * even render a billing page to subscribe from. The fake returned null; now both do.
 */
it('reads the 404 for an organization with no subscription as null', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_new' => Http::response(['error' => 'This organization has no active subscription.'], 404),
    ]);

    expect(makeHttpManagement()->subscription('org_new'))->toBeNull();
});

it('still refuses a subscription read the token may not make', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_b' => Http::response(['error' => 'This token is not permitted to act for the requested organization.'], 403),
    ]);

    expect(fn () => makeHttpManagement()->subscription('org_b'))->toThrow(BillingRefused::class);
});

it('throws on a route 404, which means a wrong base URL rather than no subscription', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_new' => Http::response(['message' => 'The route api/v1/subscriptions/org_new could not be found.'], 404),
    ]);

    expect(fn () => makeHttpManagement()->subscription('org_new'))->toThrow(BillingRefused::class);
});

it('refuses malformed catalog prices instead of inventing a free plan', function (array $priceFields): void {
    Http::fake(['billing.test/api/v1/plans' => Http::response(['plans' => [array_merge([
        'key' => 'usage', 'name' => 'Usage', 'interval' => 'month', 'product' => 'cbox-stache',
    ], $priceFields)]])]);

    expect(fn () => makeHttpManagement()->plans())->toThrow(BillingUnavailable::class);
})->with([
    'missing price' => [[]],
    'null price' => [['price' => null]],
    'missing amount' => [['price' => ['currency' => 'EUR']]],
    'null amount' => [['price' => ['minor' => null, 'currency' => 'EUR']]],
    'negative amount' => [['price' => ['minor' => -1, 'currency' => 'EUR']]],
    'fractional amount' => [['price' => ['minor' => 12.5, 'currency' => 'EUR']]],
    'nonnumeric amount' => [['price' => ['minor' => 'unknown', 'currency' => 'EUR']]],
    'missing currency' => [['price' => ['minor' => 0]]],
    'malformed nested price cannot fall back' => [['price' => [], 'price_minor' => 0, 'currency' => 'EUR']],
    'legacy missing amount' => [['currency' => 'EUR']],
    'legacy invalid amount' => [['price_minor' => false, 'currency' => 'EUR']],
]);

it('preserves explicitly free prices and integer minor units in both catalog shapes', function (array $fields, int $amount): void {
    Http::fake(['billing.test/api/v1/plans' => Http::response(['plans' => [array_merge([
        'key' => 'usage', 'name' => 'Usage', 'interval' => 'month',
    ], $fields)]])]);

    expect(makeHttpManagement()->plans()[0]->priceMinor)->toBe($amount);
})->with([
    'zero nested' => [['price' => ['minor' => 0, 'currency' => 'EUR']], 0],
    'integer string' => [['price' => ['minor' => '1250', 'currency' => 'EUR']], 1250],
    'zero legacy' => [['price_minor' => 0, 'currency' => 'eur'], 0],
]);

it('rejects the whole catalog when a row is malformed instead of silently omitting it', function (): void {
    Http::fake(['billing.test/api/v1/plans' => Http::response(['plans' => [
        ['key' => 'valid', 'price' => ['minor' => 0, 'currency' => 'EUR']],
        null,
    ]])]);

    expect(fn () => makeHttpManagement()->plans())->toThrow(BillingUnavailable::class);
});

it('preserves usage block rates and distinguishes absent rates from explicit zero', function (): void {
    Http::preventStrayRequests();
    $base = ['key' => 'usage', 'price' => ['minor' => 0, 'currency' => 'EUR']];
    Http::fake(['billing.test/api/v1/plans' => Http::response(['data' => [
        $base + ['usage_prices' => ['cpu' => ['minor' => 25, 'currency' => 'EUR', 'per' => 1000], 'free' => ['minor' => 0, 'currency' => 'EUR', 'per' => 1]]],
        $base,
        $base + ['usage_prices' => []],
    ]])]);

    [$priced, $legacy, $complex] = makeHttpManagement()->plans();
    expect($priced->usagePrices['cpu']->toArray())->toBe(['minor' => 25, 'currency' => 'EUR', 'per' => 1000])
        ->and($priced->usagePrices['free']->minor)->toBe(0)
        ->and($legacy->usagePrices)->toBeNull()
        ->and($complex->usagePrices)->toBe([]);
});

it('rejects malformed usage rates rather than inventing prices', function (mixed $prices): void {
    Http::preventStrayRequests();
    Http::fake(['billing.test/api/v1/plans' => Http::response(['data' => [
        ['key' => 'usage', 'price' => ['minor' => 0, 'currency' => 'EUR'], 'usage_prices' => $prices],
    ]])]);
    expect(fn () => makeHttpManagement()->plans())->toThrow(BillingUnavailable::class);
})->with([
    'scalar' => [false],
    'list' => [[['minor' => 10, 'currency' => 'EUR', 'per' => 1]]],
    'missing price' => [['cpu' => ['currency' => 'EUR', 'per' => 1]]],
    'negative price' => [['cpu' => ['minor' => -1, 'currency' => 'EUR', 'per' => 1]]],
    'fractional price' => [['cpu' => ['minor' => 0.5, 'currency' => 'EUR', 'per' => 1]]],
    'missing block' => [['cpu' => ['minor' => 1, 'currency' => 'EUR']]],
    'zero block' => [['cpu' => ['minor' => 1, 'currency' => 'EUR', 'per' => 0]]],
    'wrong currency' => [['cpu' => ['minor' => 1, 'currency' => 'USD', 'per' => 1]]],
]);

it('round trips usage rates through the shared fake server', function (): void {
    $rate = new UsagePrice(5, 'EUR', 1000);
    $server = (new FakeManagementTransport)->withPlan(
        new Plan('usage', 'Usage', 0, 'EUR', 'month', [], usagePrices: ['cpu' => $rate]),
    );
    expect($server->plans('EUR')[0]->usagePrices['cpu']->toArray())->toBe($rate->toArray());
});

it('preserves project breakdown including unknown dimensions and absent versus empty groups', function (): void {
    Http::preventStrayRequests();
    $groups = [
        ['dimensions' => ['project' => 'project_a', 'environment' => 'production'], 'used' => 35],
        ['dimensions' => ['project' => 'project_b', 'environment' => null], 'used' => 65],
    ];
    $usage = ['used' => 100, 'allowance' => null, 'overage' => 100];
    Http::fake(['billing.test/api/v1/usage/org_a' => Http::response(['meters' => [
        'cpu' => $usage + ['breakdown' => $groups],
        'legacy' => $usage,
        'empty' => $usage + ['breakdown' => []],
    ]])]);
    $result = makeHttpManagement()->usage('org_a');
    expect(array_map(fn ($group) => $group->toArray(), $result->for('cpu')->breakdown))->toBe($groups)
        ->and($result->for('cpu')->used)->toBe(100)
        ->and($result->for('legacy')->breakdown)->toBeNull()
        ->and($result->for('empty')->breakdown)->toBe([]);
});

it('rejects malformed usage groups instead of returning a partial financial breakdown', function (mixed $groups): void {
    Http::preventStrayRequests();
    Http::fake(['billing.test/api/v1/usage/org_a' => Http::response(['meters' => ['cpu' => [
        'used' => 100, 'allowance' => null, 'overage' => 100, 'breakdown' => $groups,
    ]]])]);
    expect(fn () => makeHttpManagement()->usage('org_a'))->toThrow(BillingUnavailable::class);
})->with([
    'scalar' => [false],
    'map' => [['project' => ['dimensions' => [], 'used' => 1]]],
    'missing quantity' => [[['dimensions' => []]]],
    'negative quantity' => [[['dimensions' => [], 'used' => -1]]],
    'fractional quantity' => [[['dimensions' => [], 'used' => 0.5]]],
    'missing dimensions' => [[['used' => 1]]],
    'numeric dimension key' => [[['used' => 1, 'dimensions' => ['project_a']]]],
    'nested dimension' => [[['used' => 1, 'dimensions' => ['project' => ['nested']]]]],
]);

it('round trips dimensioned usage through the shared fake server', function (): void {
    $group = new UsageGroup(['project' => 'project_a'], 35);
    $server = (new FakeManagementTransport)->withUsage('org_a', 'cpu',
        new MeterUsage(35, null, 35, [$group]));
    expect($server->usage('org_a')->for('cpu')->breakdown[0]->toArray())->toBe($group->toArray());
});

it('forwards stable identities for hosted checkout and portal retries', function (): void {
    Http::fake(['billing.test/api/v1/*' => Http::response(['url' => 'https://billing.test/session'])]);
    $billing = new BillingManagement(makeHttpManagement());
    $billing->createCheckoutSession('customer', 'standard', 'https://app.test/return', idempotencyKey: 'review-checkout');
    $billing->createPortalSession('customer', 'https://app.test/return', 'review-portal');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/checkout-sessions') && $r->hasHeader('Idempotency-Key', 'review-checkout'));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/portal-sessions') && $r->hasHeader('Idempotency-Key', 'review-portal'));
    expect(fn () => $billing->createPortalSession('customer', 'https://app.test/return', ''))->toThrow(InvalidArgumentException::class);
});
