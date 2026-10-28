<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\Plan;
use Illuminate\Support\Facades\Http;

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

/**
 * The 0.3 fake invented a payment intent for every paid subscribe. The server answers
 * `payment_intent: null` (`SubscriptionController::store`), so the fake now does too, and an
 * app's "confirm the first payment" branch is no longer exercised against a shape the real
 * service never sends. The transport still parses an intent if the server adds one.
 */
it('subscribes an org to a paid plan with the null payment intent the server sends', function (): void {
    $management = $this->makeBillingManagement();

    $result = $management->subscribe('org_a', 'pro');

    expect($result->subscription->plan)->toBe('pro')
        ->and($result->subscription->status)->toBe('active')
        ->and($result->paymentIntent)->toBeNull()
        ->and($result->requiresPayment())->toBeFalse();

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

// `/change` answers with the same envelope as `/preview` — what is due now, what was credited,
// the new recurring amount and the proration lines — not a subscription object. The subscription
// is still updated; it just is not what comes back.
it('changes an existing subscription plan and returns the change preview', function (): void {
    $management = $this->makeBillingManagement();
    $management->subscribe('org_a', 'free');

    $preview = $management->changePlan('org_a', 'pro');

    expect($preview)->toBeInstanceOf(ChangePreview::class)
        ->and($management->subscription('org_a')?->plan)->toBe('pro');
});

it('cancels at period end by default and immediately when asked', function (): void {
    $management = $this->makeBillingManagement();
    $management->subscribe('org_a', 'pro');

    $atEnd = $management->cancel('org_a');
    expect($atEnd->status)->toBe('active')
        ->and($atEnd->cancelsAtPeriodEnd())->toBeTrue();

    $now = $management->cancel('org_a', atPeriodEnd: false);
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

it('provisions organizations through the fake with the currency lock mirrored', function (): void {
    $fake = new FakeManagementTransport;
    $management = new BillingManagement($fake);

    $management->ensureOrganization('org_1', ['name' => 'Acme', 'billing_currency' => 'USD']);
    $management->ensureOrganization('org_1', ['name' => 'Acme ApS', 'billing_currency' => 'EUR']);

    expect($fake->organizations()['org_1'])->toBe(['name' => 'Acme ApS', 'billing_currency' => 'USD']);
});

/**
 * INT-8: `cancel()` defaulted to an IMMEDIATE cancel, while the server defaults to period end
 * (`at_period_end` absent → true). A bare `cancel($org)` cut a paying customer off mid-period.
 */
it('cancels at period end by default', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a/cancel' => Http::response(
            ['plan' => 'pro', 'status' => 'active', 'renews_at' => null],
        ),
    ]);

    $management = new BillingManagement(makeHttpManagement());
    $subscription = $management->cancel('org_a');

    Http::assertSent(fn ($request): bool => $request['at_period_end'] === true);
    expect($subscription->cancelsAtPeriodEnd())->toBeTrue();
});

it('cancels immediately only when asked to', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a/cancel' => Http::response(
            ['plan' => 'pro', 'status' => 'canceled', 'renews_at' => null],
        ),
    ]);

    (new BillingManagement(makeHttpManagement()))->cancel('org_a', atPeriodEnd: false);

    Http::assertSent(fn ($request): bool => $request['at_period_end'] === false);
});
