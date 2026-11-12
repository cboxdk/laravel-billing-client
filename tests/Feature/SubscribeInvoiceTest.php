<?php

declare(strict_types=1);

use Cbox\Billing\Client\Enums\InvoicePayment;
use Cbox\Billing\Client\Enums\PortalSection;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Http\SubscriptionPayload;
use Cbox\Billing\Client\ValueObjects\Plan;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function subscribeHttp(): HttpManagementTransport
{
    return new HttpManagementTransport(app(HttpFactory::class), 'https://billing.test/api/v1', 'secret-token');
}

/** @return array<string, mixed> */
function subscribedBody(array $extra = []): array
{
    return [
        'subscription' => ['id' => 'sub_k3j9x2m4q8w1z7v5b6n0p2r4', 'plan' => 'pro', 'status' => 'active'],
        'coupon' => null,
        'payment_intent' => null,
        ...$extra,
    ];
}

// --- Over HTTP ----------------------------------------------------------------------------

it('reads the opening invoice and the subscription id from the subscribe reply', function (): void {
    Http::fake(['billing.test/api/v1/subscriptions' => Http::response(subscribedBody(['invoice' => [
        'id' => 'inv_7Hq2kV9xTq3LmN0pR4sW1z', 'number' => 'CB-2026-0042', 'status' => 'open',
        'amount_due_minor' => 36_250, 'currency' => 'DKK', 'payment' => 'pending',
    ]]), 201)]);

    $result = subscribeHttp()->subscribe('org_a', 'pro');

    expect($result->subscription->id)->toBe('sub_k3j9x2m4q8w1z7v5b6n0p2r4')
        ->and($result->paymentIntent)->toBeNull()
        ->and($result->invoice?->id)->toBe('inv_7Hq2kV9xTq3LmN0pR4sW1z')
        ->and($result->invoice?->number)->toBe('CB-2026-0042')
        ->and($result->invoice?->status)->toBe('open')
        ->and($result->invoice?->amountDueMinor)->toBe(36_250)
        ->and($result->invoice?->currency)->toBe('DKK')
        ->and($result->invoice?->payment)->toBe(InvoicePayment::Pending)
        ->and($result->invoice?->isPending())->toBeTrue();
});

it('reads an older server: no invoice is null and no id is an empty string', function (): void {
    Http::fake(['billing.test/api/v1/subscriptions' => Http::response([
        'subscription' => ['plan' => 'pro', 'status' => 'active'], 'coupon' => null, 'payment_intent' => null,
    ], 201)]);

    $result = subscribeHttp()->subscribe('org_a', 'pro');

    expect($result->invoice)->toBeNull()
        ->and($result->subscription->id)->toBe('');
});

it('reads a null invoice when nothing was billed', function (): void {
    Http::fake(['billing.test/api/v1/subscriptions' => Http::response(subscribedBody(['invoice' => null]), 201)]);

    expect(subscribeHttp()->subscribe('org_a', 'pro')->invoice)->toBeNull();
});

it('reads a payment state it does not know as pending, and an invoice without an id as malformed', function (): void {
    Http::fake(['billing.test/api/v1/subscriptions' => Http::sequence()
        ->push(subscribedBody(['invoice' => ['id' => 'inv_1', 'number' => null, 'status' => 'open', 'amount_due_minor' => 100, 'currency' => 'EUR', 'payment' => 'processing']]), 201)
        ->push(subscribedBody(['invoice' => ['number' => 'CB-1', 'status' => 'open', 'amount_due_minor' => 100, 'currency' => 'EUR', 'payment' => 'paid']]), 201)]);

    expect(subscribeHttp()->subscribe('org_a', 'pro')->invoice?->payment)->toBe(InvoicePayment::Pending)
        ->and(fn () => subscribeHttp()->subscribe('org_a', 'pro'))->toThrow(TransportException::class);
});

it('reads the prorated invoice an immediate plan change charged', function (): void {
    Http::fake(['billing.test/api/v1/subscriptions/org_a/change' => Http::response([
        'scheduled' => false, 'due_now_minor' => 2_100, 'credit_minor' => 0, 'new_recurring_minor' => 9_900, 'lines' => [],
        'invoice' => ['id' => 'inv_3Rk8sV1xQm5TnB7cW2pL9d', 'number' => 'CB-2026-0043', 'status' => 'paid', 'amount_due_minor' => 2_100, 'currency' => 'EUR', 'payment' => 'paid'],
    ])]);

    $change = subscribeHttp()->changePlan('org_a', 'enterprise');

    expect($change->invoice?->id)->toBe('inv_3Rk8sV1xQm5TnB7cW2pL9d')
        ->and($change->invoice?->payment)->toBe(InvoicePayment::Paid);
});

it('keeps the subscription id through a kept answer', function (): void {
    $subscription = SubscriptionPayload::subscription(['id' => 'sub_1', 'plan' => 'pro', 'status' => 'active'], 'test');

    expect(SubscriptionPayload::subscription(SubscriptionPayload::toArray($subscription), 'kept')->id)->toBe('sub_1');
});

// --- Through the fake ---------------------------------------------------------------------

beforeEach(function (): void {
    $this->managementTransport()
        ->withPlan(new Plan('pro', 'Pro', 4_900, 'EUR', 'month', []))
        ->withPlan(new Plan('enterprise', 'Enterprise', 9_900, 'EUR', 'month', []))
        ->withPlan(new Plan('free', 'Free', 0, 'EUR', 'month', []));
});

it('answers a paid opening invoice the app can read back, with the subscription id', function (): void {
    $billing = $this->makeBillingManagement();

    $result = $billing->subscribe('org_a', 'pro');

    expect($result->invoice?->payment)->toBe(InvoicePayment::Paid)
        ->and($result->invoice?->amountDueMinor)->toBe(4_900)
        ->and($result->invoice?->id)->toStartWith('inv_')
        ->and($result->subscription->id)->toStartWith('sub_')
        ->and($billing->subscription('org_a')?->id)->toBe($result->subscription->id)
        ->and($billing->invoices('org_a')[0]->id)->toBe($result->invoice?->id);
});

it('simulates a charge stuck on 3-D Secure, which the app sends to the portal pay page', function (): void {
    $this->managementTransport()->chargesPending();
    $billing = $this->makeBillingManagement();

    $result = $billing->subscribe('org_a', 'pro');
    $invoice = $result->invoice;

    expect($invoice?->isPending())->toBeTrue()
        ->and($invoice?->status)->toBe('open')
        ->and($billing->invoices('org_a')[0]->isPaid())->toBeFalse();

    $portal = $billing->createPortalSession('org_a', 'https://app.test/billing', section: PortalSection::PendingPayment, invoice: $invoice?->id);

    expect($portal->url)->toContain('org_a');
});

it('answers none_due for a plan that costs nothing, even with charges pending', function (): void {
    $this->managementTransport()->chargesPending();

    expect($this->makeBillingManagement()->subscribe('org_a', 'free')->invoice?->payment)->toBe(InvoicePayment::NoneDue);
});

it('answers the prorated invoice of an upgrade', function (): void {
    $billing = $this->makeBillingManagement();
    $billing->subscribe('org_a', 'pro');

    $change = $billing->changePlan('org_a', 'enterprise');

    expect($change->invoice?->payment)->toBe(InvoicePayment::Paid)
        ->and($change->invoice?->amountDueMinor)->toBe($change->dueNowMinor);
});
