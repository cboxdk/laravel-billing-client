<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\Plan;

beforeEach(function (): void {
    $this->managementTransport()
        ->withPlan(new Plan('free', 'Free', 0, 'usd', 'month', [
            new Entitlement('api.calls', enabled: true, allowance: 1_000, weight: 1.0, overage: 'block'),
        ]))
        ->withPlan(new Plan('pro', 'Pro', 4_900, 'usd', 'month', [
            new Entitlement('api.calls', enabled: true, allowance: 100_000, weight: 1.0, overage: 'bill'),
        ]));
});

it('lists plans with their entitlements', function (): void {
    $plans = $this->makeBillingManagement()->plans();

    expect($plans)->toHaveCount(2)
        ->and($plans[0]->key)->toBe('free')
        ->and($plans[1]->key)->toBe('pro')
        ->and($plans[1]->priceMinor)->toBe(4_900)
        ->and($plans[1]->entitlement('api.calls')?->allowance)->toBe(100_000);
});

it('returns null for an org with no subscription', function (): void {
    expect($this->makeBillingManagement()->subscription('org_a'))->toBeNull();
});

it('subscribes an org to a paid plan and surfaces the payment intent', function (): void {
    $management = $this->makeBillingManagement();

    $result = $management->subscribe('org_a', 'pro');

    expect($result->subscription->plan)->toBe('pro')
        ->and($result->subscription->status)->toBe('active')
        ->and($result->requiresPayment())->toBeTrue()
        ->and($result->paymentIntent?->status)->toBe('requires_confirmation');

    // The subscription is now readable.
    expect($management->subscription('org_a')?->plan)->toBe('pro');
});

it('subscribes to a free plan with no payment intent', function (): void {
    $result = $this->makeBillingManagement()->subscribe('org_a', 'free');

    expect($result->requiresPayment())->toBeFalse()
        ->and($result->paymentIntent)->toBeNull();
});

it('previews a plan change with prorated credit and net due-now', function (): void {
    $management = $this->makeBillingManagement();
    $management->subscribe('org_a', 'pro'); // now on a 4900 plan

    $preview = $management->previewChange('org_a', 'free'); // downgrade

    // Credit for the current plan reduces the net due-now to zero on a downgrade.
    expect($preview->creditMinor)->toBe(4_900)
        ->and($preview->newRecurringMinor)->toBe(0)
        ->and($preview->dueNowMinor)->toBe(0)
        ->and($preview->lines)->not->toBeEmpty();
});

it('changes an existing subscription plan', function (): void {
    $management = $this->makeBillingManagement();
    $management->subscribe('org_a', 'free');

    $subscription = $management->changePlan('org_a', 'pro');

    expect($subscription->plan)->toBe('pro')
        ->and($management->subscription('org_a')?->plan)->toBe('pro');
});

it('cancels immediately by default and at period end when asked', function (): void {
    $management = $this->makeBillingManagement();
    $management->subscribe('org_a', 'pro');

    $atEnd = $management->cancel('org_a', atPeriodEnd: true);
    expect($atEnd->status)->toBe('active')
        ->and($atEnd->cancelsAtPeriodEnd())->toBeTrue();

    $now = $management->cancel('org_a');
    expect($now->status)->toBe('canceled');
});

it('reads per-meter usage with remaining allowance', function (): void {
    $this->managementTransport()->withUsage('org_a', 'api.calls', new MeterUsage(used: 750, allowance: 1_000, overage: 0));
    $management = $this->makeBillingManagement();

    $usage = $management->usage('org_a');

    expect($usage->for('api.calls')?->used)->toBe(750)
        ->and($usage->for('api.calls')?->remaining())->toBe(250)
        ->and($usage->for('unknown'))->toBeNull();
});

it('lists invoices', function (): void {
    $this->managementTransport()->withInvoice('org_a', new Invoice('INV-1', new DateTimeImmutable('2026-01-01'), 4_900, 'usd', 'paid'));
    $management = $this->makeBillingManagement();

    $invoices = $management->invoices('org_a');

    expect($invoices)->toHaveCount(1)
        ->and($invoices[0]->number)->toBe('INV-1')
        ->and($invoices[0]->isPaid())->toBeTrue();
});

it('denies by default: an unknown plan is refused, not silently accepted', function (): void {
    expect(fn () => $this->makeBillingManagement()->subscribe('org_a', 'ghost-plan'))
        ->toThrow(TransportException::class);
});

it('denies by default when the management API is unreachable', function (): void {
    $this->managementTransport()->takeDown();

    expect(fn () => $this->makeBillingManagement()->plans())
        ->toThrow(TransportException::class);
});

it('refuses to change or cancel a subscription that does not exist', function (): void {
    $management = $this->makeBillingManagement();

    expect(fn () => $management->changePlan('org_a', 'pro'))->toThrow(TransportException::class);
    expect(fn () => $management->cancel('org_a'))->toThrow(TransportException::class);
});
