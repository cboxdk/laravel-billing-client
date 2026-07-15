<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeHttpManagement(): HttpManagementTransport
{
    return new HttpManagementTransport(
        app(HttpFactory::class),
        'https://billing.test',
        'secret-token',
    );
}

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
                ['description' => 'Pro plan', 'amount_minor' => 4_900],
                ['description' => 'Unused credit', 'amount_minor' => -2_500],
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
        'billing.test/api/v1/subscriptions/org_a/cancel' => Http::response([
            'subscription' => ['plan' => 'pro', 'status' => 'active', 'renews_at' => null],
        ]),
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
