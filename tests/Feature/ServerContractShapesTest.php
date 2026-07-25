<?php

declare(strict_types=1);

use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Illuminate\Support\Facades\Http;

/**
 * Replays the shapes the SERVER actually emits, taken from `cbox-billing`'s controllers and its
 * published OpenAPI contract — not from what this SDK assumed.
 *
 * Four methods were broken against the real API in every call, and the existing suite could not
 * see it because each test faked a response in the SDK's own assumed shape. A double that agrees
 * with the code it doubles proves nothing; these fixtures deliberately disagree with the old
 * assumptions, so a regression to any of them fails here.
 *
 * Every case below corresponds to a real defect:
 *  - `/change` returns a preview envelope, not `{subscription: …}`
 *  - `/cancel` returns the subscription UNWRAPPED
 *  - proration lines carry `minor`, not `amount_minor`
 *  - `/payment-intents` validates `invoice` + `amount`, not `reference` + `amount_minor`
 *  - `/payment-methods/{org}/default` validates `id`, not `payment_method`
 *  - `/usage/{org}` emits `allowance: null` for an unlimited meter
 */
it('parses the change envelope the server actually returns', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a/change' => Http::response([
            'scheduled' => false,
            'due_now_minor' => 2_400,
            'credit_minor' => 2_500,
            'new_recurring_minor' => 4_900,
            'effective_at' => '2026-07-16T12:00:00+00:00',
            'credit_delta' => ['forfeited' => 0, 'granted' => 0, 'carried' => 0],
            'lines' => [['description' => 'Pro plan', 'minor' => 4_900]],
        ]),
    ]);

    $preview = makeHttpManagement()->changePlan('org_a', 'pro');

    expect($preview)->toBeInstanceOf(ChangePreview::class)
        ->and($preview->dueNowMinor)->toBe(2_400)
        ->and($preview->lines[0]->amountMinor)->toBe(4_900);
});

it('parses an unwrapped subscription from cancel', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a/cancel' => Http::response(
            ['plan' => 'pro', 'status' => 'canceled', 'renews_at' => null],
        ),
    ]);

    expect(makeHttpManagement()->cancel('org_a', false)->status)->toBe('canceled');
});

/**
 * A single unlimited meter used to break the ENTIRE usage view, not just its own row — the
 * allowance was typed as a required int and the server legitimately sends null.
 */
it('parses a usage summary containing an unlimited meter', function (): void {
    Http::fake([
        'billing.test/api/v1/usage/org_a' => Http::response([
            'meters' => [
                'api.requests' => ['used' => 120, 'allowance' => 1_000, 'overage' => 0],
                'seats' => ['used' => 4, 'allowance' => null, 'overage' => 0],
            ],
            'period' => ['start' => '2026-07-01T00:00:00+00:00', 'end' => '2026-07-31T23:59:59+00:00'],
        ]),
    ]);

    $usage = makeHttpManagement()->usage('org_a');

    expect($usage->meters['api.requests']->allowance)->toBe(1_000)
        ->and($usage->meters['seats']->allowance)->toBeNull()
        ->and($usage->meters['seats']->used)->toBe(4);
});

it('posts the payment-intent field names the server validates', function (): void {
    // The server's full intent body — gateway, publishable_key, client_secret, status, reference.
    Http::fake(['billing.test/api/v1/payment-intents' => Http::response([
        'gateway' => 'stripe',
        'publishable_key' => 'pk_test_1',
        'client_secret' => 'pi_1_secret',
        'status' => 'succeeded',
        'reference' => 'pi_1',
    ])]);

    makeHttpManagement()->createPaymentIntent('org_a', amountMinor: 4_900, currency: 'usd');

    Http::assertSent(fn ($request): bool => $request['amount'] === 4_900
        && $request['currency'] === 'usd'
        && ! isset($request['amount_minor']));
});

it('posts the default-payment-method field name the server validates', function (): void {
    Http::fake(['billing.test/api/v1/payment-methods/org_a/default' => Http::response([])]);

    makeHttpManagement()->setDefaultPaymentMethod('org_a', 'pm_2');

    Http::assertSent(fn ($request): bool => $request['id'] === 'pm_2' && ! isset($request['payment_method']));
});
