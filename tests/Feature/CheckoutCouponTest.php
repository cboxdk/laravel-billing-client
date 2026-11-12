<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\ValueObjects\Plan;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function couponHttp(): HttpManagementTransport
{
    return new HttpManagementTransport(app(HttpFactory::class), 'https://billing.test/api/v1', 'secret-token');
}

function refusalOf(Closure $call): BillingRefused
{
    try {
        $call();
    } catch (BillingRefused $e) {
        return $e;
    }

    throw new LogicException('Expected a BillingRefused.');
}

beforeEach(function (): void {
    $this->managementTransport()
        ->withPlan(new Plan('pro', 'Pro', 4_900, 'usd', 'month', []))
        ->withPlan(new Plan('team', 'Team', 9_900, 'usd', 'month', []));
});

// --- Over HTTP ----------------------------------------------------------------------------

it('sends the coupon with the checkout only when one is given', function (): void {
    Http::fake(['billing.test/api/v1/checkout-sessions' => Http::response(['url' => 'https://billing.test/pay/cs_1', 'expires_at' => '2026-11-12T13:00:00+00:00'], 201)]);

    couponHttp()->createCheckoutSession('org_a', 'pro', 'https://app.test/return');
    couponHttp()->createCheckoutSession('org_a', 'pro', 'https://app.test/return', coupon: 'LAUNCH100');

    $sent = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();

    expect($sent[0])->toBe(['org' => 'org_a', 'plan' => 'pro', 'return_url' => 'https://app.test/return'])
        ->and($sent[1])->toBe(['org' => 'org_a', 'plan' => 'pro', 'return_url' => 'https://app.test/return', 'coupon' => 'LAUNCH100']);
});

it('sends the coupon alongside the currency and the idempotency key', function (): void {
    Http::fake(['billing.test/api/v1/checkout-sessions' => Http::response(['url' => 'https://billing.test/pay/cs_1'], 201)]);

    couponHttp()->createCheckoutSession('org_a', 'pro', 'https://app.test/return', 'EUR', 'checkout-1', 'LAUNCH100');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'checkout-1')
        && $request['currency'] === 'EUR'
        && $request['coupon'] === 'LAUNCH100');
});

it('turns a refused coupon (422) into BillingRefused carrying the reason', function (): void {
    Http::fake(['billing.test/api/v1/checkout-sessions' => Http::response(['error' => 'Promo code [NOPE] is unknown.'], 422)]);

    $refused = refusalOf(fn () => couponHttp()->createCheckoutSession('org_a', 'pro', 'https://app.test/return', coupon: 'NOPE'));

    expect($refused->status)->toBe(422)
        ->and($refused->body)->toContain('NOPE');
});

// --- Through the fake ---------------------------------------------------------------------

it('opens a checkout with a seeded coupon and records it for the app test', function (): void {
    $this->managementTransport()->withCoupon('launch100');

    $session = $this->makeBillingManagement()->createCheckoutSession('org_a', 'pro', 'https://app.test/return', coupon: ' Launch100 ');

    expect($session->url)->toContain('org_a')
        ->and($this->managementTransport()->checkoutSessions())->toBe([[
            'org' => 'org_a',
            'plan' => 'pro',
            'currency' => null,
            'coupon' => 'LAUNCH100',
            'url' => $session->url,
        ]]);
});

it('records a checkout without a coupon, and treats a blank one as none', function (): void {
    $billing = $this->makeBillingManagement();

    $billing->createCheckoutSession('org_a', 'pro', 'https://app.test/return', 'usd');
    $billing->createCheckoutSession('org_a', 'pro', 'https://app.test/return', coupon: '  ');

    expect(array_column($this->managementTransport()->checkoutSessions(), 'coupon'))->toBe([null, null])
        ->and($this->managementTransport()->checkoutSessions()[0]['currency'])->toBe('usd');
});

it('refuses a coupon the server would not apply with a 422, and opens no session', function (Closure $seed, string $plan, string $code): void {
    $seed($this->managementTransport());

    $refused = refusalOf(fn () => $this->makeBillingManagement()->createCheckoutSession('org_a', $plan, 'https://app.test/return', coupon: $code));

    expect($refused->status)->toBe(422)
        ->and($this->managementTransport()->checkoutSessions())->toBe([]);
})->with([
    'unknown code' => [fn ($fake) => $fake, 'pro', 'GHOST'],
    'code for another plan' => [fn ($fake) => $fake->withCoupon('TEAMONLY', 'team'), 'pro', 'TEAMONLY'],
]);

it('applies a plan-bound coupon to its plan', function (): void {
    $this->managementTransport()->withCoupon('TEAMONLY', 'team');

    $this->makeBillingManagement()->createCheckoutSession('org_a', 'team', 'https://app.test/return', coupon: 'teamonly');

    expect($this->managementTransport()->checkoutSessions()[0]['coupon'])->toBe('TEAMONLY');
});

it('refuses a coupon on one-off products alone, as the server does', function (): void {
    $answer = $this->managementTransport()->withOneOffProduct('setup')->withCoupon('LAUNCH100')->respond('POST', '/api/v1/checkout-sessions', [
        'org' => 'org_a', 'items' => [['product' => 'setup']], 'return_url' => 'https://app.test', 'coupon' => 'LAUNCH100',
    ]);

    expect($answer->status)->toBe(422);
});

it('still answers an unknown plan with a 404 before looking at the coupon', function (): void {
    expect(fn () => $this->makeBillingManagement()->createCheckoutSession('org_a', 'ghost', 'https://app.test/return', coupon: 'GHOST'))
        ->toThrow(TransportException::class);

    expect(refusalOf(fn () => $this->makeBillingManagement()->createCheckoutSession('org_a', 'ghost', 'https://app.test/return', coupon: 'GHOST'))->status)->toBe(404);
});
