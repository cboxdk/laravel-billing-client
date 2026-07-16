<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;

beforeEach(function (): void {
    $this->managementTransport()->withPlan(
        new Plan('pro', 'Pro', 4_900, 'usd', 'month', [
            new Entitlement('api.calls', enabled: true, allowance: 100_000, weight: 1.0, overage: 'bill'),
        ]),
    );
});

// --- Path A: hosted checkout & portal --------------------------------------------------

it('opens a hosted checkout session that has not yet expired', function (): void {
    $session = $this->makeBillingManagement()->createCheckoutSession('org_a', 'pro', 'https://app.test/return');

    expect($session->url)->toContain('org_a')
        ->and($session->expiresAt)->not->toBeNull()
        ->and($session->isExpired())->toBeFalse();
});

it('denies a checkout session for an unknown plan', function (): void {
    expect(fn () => $this->makeBillingManagement()->createCheckoutSession('org_a', 'ghost', 'https://app.test/return'))
        ->toThrow(TransportException::class);
});

it('reports an expired checkout session as expired', function (): void {
    $this->managementTransport()->expireSessions();

    $session = $this->makeBillingManagement()->createCheckoutSession('org_a', 'pro', 'https://app.test/return');

    expect($session->isExpired())->toBeTrue();
});

it('opens a billing-portal session', function (): void {
    $session = $this->makeBillingManagement()->createPortalSession('org_a', 'https://app.test/account');

    expect($session->url)->toContain('org_a');
});

// --- Path B: intents & payment methods -------------------------------------------------

it('creates a setup intent the front-end can mount and that needs SCA', function (): void {
    $intent = $this->makeBillingManagement()->createSetupIntent('org_a');

    expect($intent->gateway)->not->toBe('')
        ->and($intent->publishableKey)->not->toBe('')
        ->and($intent->clientSecret)->not->toBeNull()
        ->and($intent->requiresAction())->toBeTrue();
});

it('creates a payment intent from an ad-hoc amount', function (): void {
    $intent = $this->makeBillingManagement()->createPaymentIntent('org_a', amountMinor: 4_900, currency: 'usd');

    expect($intent->clientSecret)->not->toBeNull()
        ->and($intent->requiresAction())->toBeTrue();
});

it('creates a payment intent from an existing reference', function (): void {
    $intent = $this->makeBillingManagement()->createPaymentIntent('org_a', reference: 'inv_123');

    expect($intent->reference)->toBe('inv_123');
});

it('rejects a payment intent with neither a reference nor an amount', function (): void {
    expect(fn () => $this->makeBillingManagement()->createPaymentIntent('org_a'))
        ->toThrow(InvalidArgumentException::class);
});

it('lists, re-defaults and removes stored payment methods', function (): void {
    $this->managementTransport()
        ->withPaymentMethod('org_a', new PaymentMethod('pm_1', 'visa', '4242', 12, 2030, isDefault: true))
        ->withPaymentMethod('org_a', new PaymentMethod('pm_2', 'mastercard', '4444', 1, 2031, isDefault: false));

    $management = $this->makeBillingManagement();

    expect($management->paymentMethods('org_a'))->toHaveCount(2);

    $management->setDefaultPaymentMethod('org_a', 'pm_2');

    $methods = $management->paymentMethods('org_a');
    $default = array_values(array_filter($methods, static fn (PaymentMethod $m): bool => $m->isDefault));

    expect($default)->toHaveCount(1)
        ->and($default[0]->id)->toBe('pm_2');

    $management->removePaymentMethod('org_a', 'pm_1');

    expect($management->paymentMethods('org_a'))->toHaveCount(1)
        ->and($management->paymentMethods('org_a')[0]->id)->toBe('pm_2');
});

it('denies by default when re-defaulting or removing an unknown method', function (): void {
    $this->managementTransport()->withPaymentMethod('org_a', new PaymentMethod('pm_1', 'visa', '4242', 12, 2030, isDefault: true));
    $management = $this->makeBillingManagement();

    expect(fn () => $management->setDefaultPaymentMethod('org_a', 'ghost'))->toThrow(TransportException::class);
    expect(fn () => $management->removePaymentMethod('org_a', 'ghost'))->toThrow(TransportException::class);
});

it('denies by default when the management API is unreachable', function (): void {
    $this->managementTransport()->takeDown();
    $management = $this->makeBillingManagement();

    expect(fn () => $management->createSetupIntent('org_a'))->toThrow(TransportException::class);
    expect(fn () => $management->createCheckoutSession('org_a', 'pro', 'https://app.test/return'))->toThrow(TransportException::class);
    expect(fn () => $management->paymentMethods('org_a'))->toThrow(TransportException::class);
});
