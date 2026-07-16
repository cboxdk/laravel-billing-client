<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function paymentsHttp(): HttpManagementTransport
{
    return new HttpManagementTransport(
        app(HttpFactory::class),
        'https://billing.test',
        'secret-token',
    );
}

it('creates a hosted checkout session over HTTP with a bearer token', function (): void {
    Http::fake([
        'billing.test/api/v1/checkout-sessions' => Http::response([
            'url' => 'https://billing.test/pay/cs_1',
            'expires_at' => '2026-07-16T13:00:00+00:00',
        ]),
    ]);

    $session = paymentsHttp()->createCheckoutSession('org_a', 'pro', 'https://app.test/return', 'usd');

    expect($session->url)->toBe('https://billing.test/pay/cs_1')
        ->and($session->expiresAt?->format('H:i'))->toBe('13:00');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->url() === 'https://billing.test/api/v1/checkout-sessions'
        && $request['org'] === 'org_a'
        && $request['plan'] === 'pro'
        && $request['return_url'] === 'https://app.test/return'
        && $request['currency'] === 'usd');
});

it('creates a portal session over HTTP', function (): void {
    Http::fake([
        'billing.test/api/v1/portal-sessions' => Http::response(['url' => 'https://billing.test/portal/org_a']),
    ]);

    $session = paymentsHttp()->createPortalSession('org_a', 'https://app.test/account');

    expect($session->url)->toBe('https://billing.test/portal/org_a');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/portal-sessions'
        && $request['return_url'] === 'https://app.test/account');
});

it('creates a setup intent over HTTP with the gateway descriptor', function (): void {
    Http::fake([
        'billing.test/api/v1/setup-intents' => Http::response([
            'gateway' => 'acme',
            'publishable_key' => 'pk_live_1',
            'client_secret' => 'seti_secret_1',
            'status' => 'requires_action',
            'reference' => 'seti_1',
        ]),
    ]);

    $intent = paymentsHttp()->createSetupIntent('org_a');

    expect($intent->gateway)->toBe('acme')
        ->and($intent->publishableKey)->toBe('pk_live_1')
        ->and($intent->clientSecret)->toBe('seti_secret_1')
        ->and($intent->requiresAction())->toBeTrue()
        ->and($intent->reference)->toBe('seti_1');
});

it('creates a payment intent over HTTP from an amount', function (): void {
    Http::fake([
        'billing.test/api/v1/payment-intents' => Http::response([
            'payment_intent' => [
                'gateway' => 'acme',
                'publishable_key' => 'pk_live_1',
                'client_secret' => 'pi_secret_1',
                'status' => 'requires_confirmation',
                'reference' => 'pi_1',
            ],
        ]),
    ]);

    $intent = paymentsHttp()->createPaymentIntent('org_a', amountMinor: 4_900, currency: 'usd');

    expect($intent->clientSecret)->toBe('pi_secret_1')
        ->and($intent->reference)->toBe('pi_1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/payment-intents'
        && $request['amount_minor'] === 4_900
        && $request['currency'] === 'usd'
        && ! isset($request['reference']));
});

it('rejects a payment intent request with neither a reference nor an amount', function (): void {
    expect(fn () => paymentsHttp()->createPaymentIntent('org_a'))
        ->toThrow(InvalidArgumentException::class);
});

it('lists payment methods over HTTP', function (): void {
    Http::fake([
        'billing.test/api/v1/payment-methods/org_a' => Http::response([
            'payment_methods' => [
                ['id' => 'pm_1', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'is_default' => true],
            ],
        ]),
    ]);

    $methods = paymentsHttp()->paymentMethods('org_a');

    expect($methods)->toHaveCount(1)
        ->and($methods[0]->id)->toBe('pm_1')
        ->and($methods[0]->last4)->toBe('4242')
        ->and($methods[0]->isDefault)->toBeTrue();
});

it('sets a default payment method over HTTP', function (): void {
    Http::fake([
        'billing.test/api/v1/payment-methods/org_a/default' => Http::response([], 204),
    ]);

    paymentsHttp()->setDefaultPaymentMethod('org_a', 'pm_2');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://billing.test/api/v1/payment-methods/org_a/default'
        && $request['payment_method'] === 'pm_2');
});

it('removes a payment method over HTTP with a DELETE', function (): void {
    Http::fake([
        'billing.test/api/v1/payment-methods/org_a/pm_1' => Http::response([], 204),
    ]);

    paymentsHttp()->removePaymentMethod('org_a', 'pm_1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://billing.test/api/v1/payment-methods/org_a/pm_1');
});

it('raises a transport exception when a payment method command fails (deny-by-default)', function (): void {
    Http::fake([
        'billing.test/api/v1/payment-methods/org_a/default' => Http::response([], 500),
    ]);

    expect(fn () => paymentsHttp()->setDefaultPaymentMethod('org_a', 'pm_2'))
        ->toThrow(TransportException::class);
});

it('raises a transport exception on a malformed setup-intent body', function (): void {
    Http::fake([
        'billing.test/api/v1/setup-intents' => Http::response(['status' => 'requires_action'], 200),
    ]);

    // Missing gateway / publishable_key / client_secret is a malformed response.
    expect(fn () => paymentsHttp()->createSetupIntent('org_a'))
        ->toThrow(TransportException::class);
});
