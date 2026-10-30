<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\CheckoutSession;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PortalSession;
use Cbox\Billing\Client\ValueObjects\SetupIntent;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionChange;
use Cbox\Billing\Client\ValueObjects\SubscriptionDiscount;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * 0.4.2: the subscription discount, organization_ref and pause_reason on every subscription
 * payload, the change feed, and reactivate. Every new field is optional on the wire, so an older
 * server's payload must still parse.
 */

/** @return array<string, mixed> */
function percentDiscount(): array
{
    return [
        'code' => 'SAVE20',
        'name' => 'Launch offer',
        'percent_off' => 20,
        'amount_off_minor' => null,
        'currency' => null,
        'duration' => 'repeating',
        'ends_at' => '2026-10-01T00:00:00+00:00',
    ];
}

/** @return array<string, mixed> */
function feedRow(int $id, string $ref, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'organization_id' => '01JBX7Q3W9T0S2M4V6Y8Z1A3C'.$id,
        'organization_ref' => $ref,
        'plan' => 'pro',
        'product' => 'acme-app',
        'status' => 'active',
        'paused' => false,
        'pause_reason' => null,
        'seats' => 5,
        'period_start' => '2026-10-01T00:00:00+00:00',
        'period_end' => '2026-11-01T00:00:00+00:00',
        'cancel_at_period_end' => false,
        'trial_ends_at' => null,
        'canceled_at' => null,
        'discount' => null,
        'updated_at' => '2026-10-28T09:00:00+00:00',
    ], $overrides);
}

it('reads the discount, organization ref and pause reason off a subscription', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/tenant-42' => Http::response([
            'plan' => 'pro',
            'organization_ref' => 'tenant-42',
            'status' => 'active',
            'paused' => true,
            'pause_reason' => 'billing_address',
            'period_start' => '2026-10-01T00:00:00+00:00',
            'period_end' => '2026-11-01T00:00:00+00:00',
            'renews_at' => '2026-11-01T00:00:00+00:00',
            'discount' => percentDiscount(),
        ]),
    ]);

    $subscription = makeHttpManagement()->subscription('tenant-42');

    expect($subscription?->organizationRef)->toBe('tenant-42')
        ->and($subscription?->pauseReason)->toBe('billing_address')
        ->and($subscription?->paused)->toBeTrue()
        ->and($subscription?->isPaused())->toBeTrue()
        ->and($subscription?->discount)->toBeInstanceOf(SubscriptionDiscount::class)
        ->and($subscription?->discount?->code)->toBe('SAVE20')
        ->and($subscription?->discount?->name)->toBe('Launch offer')
        ->and($subscription?->discount?->percentOff)->toBe(20)
        ->and($subscription?->discount?->isPercentage())->toBeTrue()
        ->and($subscription?->discount?->amountOffMinor)->toBeNull()
        ->and($subscription?->discount?->duration)->toBe('repeating')
        ->and($subscription?->discount?->endsAt?->format('Y-m-d'))->toBe('2026-10-01');
});

it('reads a fixed-amount forever discount', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/tenant-42' => Http::response([
            'plan' => 'pro',
            'status' => 'active',
            'discount' => [
                'code' => 'TENOFF',
                'name' => null,
                'percent_off' => null,
                'amount_off_minor' => 1_000,
                'currency' => 'DKK',
                'duration' => 'forever',
                'ends_at' => null,
            ],
        ]),
    ]);

    $discount = makeHttpManagement()->subscription('tenant-42')?->discount;

    expect($discount?->isPercentage())->toBeFalse()
        ->and($discount?->amountOffMinor)->toBe(1_000)
        ->and($discount?->currency)->toBe('DKK')
        ->and($discount?->name)->toBeNull()
        ->and($discount?->isForever())->toBeTrue()
        ->and($discount?->endsAt)->toBeNull();
});

it('parses an older server subscription without the new fields', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a' => Http::response([
            'plan' => 'pro', 'status' => 'active', 'paused' => false, 'seats' => 1,
            'period_start' => '2026-10-01T00:00:00+00:00', 'period_end' => '2026-11-01T00:00:00+00:00',
            'renews_at' => '2026-11-01T00:00:00+00:00', 'trial_ends_at' => null, 'canceled_at' => null,
            'pending_change' => null, 'add_ons' => [],
        ]),
    ]);

    $subscription = makeHttpManagement()->subscription('org_a');

    expect($subscription?->plan)->toBe('pro')
        ->and($subscription?->discount)->toBeNull()
        ->and($subscription?->organizationRef)->toBeNull()
        ->and($subscription?->pauseReason)->toBeNull()
        ->and($subscription?->isPaused())->toBeFalse();
});

it('treats a discount without its code as a malformed response', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_a' => Http::response([
            'plan' => 'pro', 'status' => 'active', 'discount' => ['percent_off' => 20, 'duration' => 'once'],
        ]),
    ]);

    expect(fn () => makeHttpManagement()->subscription('org_a'))->toThrow(BillingUnavailable::class);
});

it('reads the discount off a preview, and none from an older server', function (): void {
    $preview = [
        'due_now_minor' => 2_400, 'credit_minor' => 2_500, 'new_recurring_minor' => 4_900,
        'effective_at' => '2026-10-28T12:00:00+00:00', 'lines' => [],
    ];

    Http::fake([
        'billing.test/api/v1/subscriptions/org_a/preview' => Http::response($preview + ['discount' => percentDiscount()]),
        'billing.test/api/v1/subscriptions/org_b/preview' => Http::response($preview),
    ]);

    expect(makeHttpManagement()->previewChange('org_a', 'pro')->discount?->code)->toBe('SAVE20')
        ->and(makeHttpManagement()->previewChange('org_b', 'pro')->discount)->toBeNull();
});

it('reads a page of the change feed and sends the watermark, cursor and limit', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions?*' => Http::response([
            'data' => [
                feedRow(42, 'tenant-42', ['discount' => percentDiscount(), 'cancel_at_period_end' => true]),
                feedRow(43, 'tenant-43', ['status' => 'canceled', 'canceled_at' => '2026-10-20T00:00:00+00:00', 'plan' => null, 'product' => null]),
            ],
            'has_more' => true,
            'next_cursor' => 'abc123',
        ]),
    ]);

    $page = makeHttpManagement()->subscriptions(new DateTimeImmutable('2026-10-28T09:00:00+02:00'), 'prev', 2);

    expect($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->toBe('abc123')
        ->and($page->data)->toHaveCount(2);

    $first = $page->data[0];

    expect($first)->toBeInstanceOf(SubscriptionChange::class)
        ->and($first->id)->toBe(42)
        ->and($first->organizationId)->toBe('01JBX7Q3W9T0S2M4V6Y8Z1A3C42')
        ->and($first->organizationRef)->toBe('tenant-42')
        ->and($first->organizationKey())->toBe('tenant-42')
        ->and($first->plan)->toBe('pro')
        ->and($first->product)->toBe('acme-app')
        ->and($first->seats)->toBe(5)
        ->and($first->cancelAtPeriodEnd)->toBeTrue()
        ->and($first->discount?->percentOff)->toBe(20)
        ->and($first->updatedAt?->format(DATE_ATOM))->toBe('2026-10-28T09:00:00+00:00')
        ->and($page->data[1]->plan)->toBeNull()
        ->and($page->data[1]->status)->toBe('canceled')
        ->and($page->data[1]->canceledAt?->format('Y-m-d'))->toBe('2026-10-20');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://billing.test/api/v1/subscriptions?updated_since=2026-10-28T09%3A00%3A00%2B02%3A00&cursor=prev&limit=2');
});

it('asks for the first page with no query string', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions' => Http::response(['data' => [], 'has_more' => false, 'next_cursor' => null]),
    ]);

    $page = makeHttpManagement()->subscriptions();

    expect($page->data)->toBe([])
        ->and($page->hasMore)->toBeFalse()
        ->and($page->nextCursor)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/subscriptions');
});

it('parses an older feed row without organization_ref, pause_reason or discount', function (): void {
    $row = feedRow(7, 'unused');
    unset($row['organization_ref'], $row['pause_reason'], $row['discount']);

    Http::fake([
        'billing.test/api/v1/subscriptions' => Http::response(['data' => [$row], 'has_more' => false, 'next_cursor' => null]),
    ]);

    $change = makeHttpManagement()->subscriptions()->data[0];

    expect($change->organizationRef)->toBeNull()
        ->and($change->organizationKey())->toBe($change->organizationId)
        ->and($change->pauseReason)->toBeNull()
        ->and($change->discount)->toBeNull();
});

it('treats a feed without a data array as malformed', function (): void {
    Http::fake(['billing.test/api/v1/subscriptions' => Http::response(['subscriptions' => []])]);

    expect(fn () => makeHttpManagement()->subscriptions())->toThrow(BillingUnavailable::class);
});

it('reactivates over HTTP and reads the unwrapped subscription', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/tenant-42/reactivate' => Http::response([
            'plan' => 'pro', 'organization_ref' => 'tenant-42', 'status' => 'active',
            'renews_at' => '2026-11-01T00:00:00+00:00', 'discount' => null,
        ]),
    ]);

    $subscription = makeHttpManagement()->reactivate('tenant-42');

    expect($subscription->cancelsAtPeriodEnd())->toBeFalse()
        ->and($subscription->organizationRef)->toBe('tenant-42');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://billing.test/api/v1/subscriptions/tenant-42/reactivate');
});

it('surfaces a 409 and a 404 from reactivate as refusals', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/active/reactivate' => Http::response(['error' => 'This subscription cannot be reactivated.'], 409),
        'billing.test/api/v1/subscriptions/none/reactivate' => Http::response(['error' => 'This organization has no subscription.'], 404),
    ]);

    try {
        makeHttpManagement()->reactivate('active');
        $this->fail('A 409 was accepted.');
    } catch (BillingRefused $e) {
        expect($e->status)->toBe(409);
    }

    try {
        makeHttpManagement()->reactivate('none');
        $this->fail('A 404 was accepted.');
    } catch (BillingRefused $e) {
        expect($e->status)->toBe(404);
    }
});

describe('through the fake', function (): void {
    beforeEach(function (): void {
        $this->managementTransport()->withPlan(new Plan('pro', 'Pro', 4_900, 'usd', 'month', [
            new Entitlement('api.calls', enabled: true, allowance: 100_000, weight: 1.0, overage: 'bill'),
        ]));
    });

    it('undoes a period-end cancel and refuses to reactivate an active subscription', function (): void {
        $management = $this->makeBillingManagement();
        $management->subscribe('tenant-1', 'pro');
        $management->cancel('tenant-1');

        expect($management->subscription('tenant-1')?->cancelsAtPeriodEnd())->toBeTrue();

        $reactivated = $management->reactivate('tenant-1');

        expect($reactivated->cancelsAtPeriodEnd())->toBeFalse()
            ->and($management->subscription('tenant-1')?->cancelsAtPeriodEnd())->toBeFalse();

        expect(fn () => $management->reactivate('tenant-1'))->toThrow(BillingRefused::class);
    });

    it('brings back a subscription canceled outright, and 404s with none at all', function (): void {
        $management = $this->makeBillingManagement();
        $management->subscribe('tenant-1', 'pro');
        $management->cancel('tenant-1', atPeriodEnd: false);

        expect($management->subscription('tenant-1'))->toBeNull()
            ->and($management->reactivate('tenant-1')->status)->toBe('active')
            ->and($management->subscription('tenant-1')?->plan)->toBe('pro');

        try {
            $management->reactivate('tenant-none');
            $this->fail('Reactivating nothing was accepted.');
        } catch (BillingRefused $e) {
            expect($e->status)->toBe(404);
        }
    });

    it('resumes a paused subscription', function (): void {
        $this->managementTransport()->withSubscription('tenant-p', new Subscription(
            'pro', 'active', renewsAt: new DateTimeImmutable('+1 month'), pauseReason: 'billing_address', paused: true,
        ));

        $management = $this->makeBillingManagement();

        expect($management->subscription('tenant-p')?->pauseReason)->toBe('billing_address')
            ->and($management->reactivate('tenant-p')->isPaused())->toBeFalse();
    });

    it('carries the discount through subscription, preview and change', function (): void {
        $discount = new SubscriptionDiscount('SAVE20', 'forever', percentOff: 20);
        $this->managementTransport()->withSubscription('tenant-d', new Subscription(
            'pro', 'active', renewsAt: new DateTimeImmutable('+1 month'), discount: $discount,
        ));

        $management = $this->makeBillingManagement();

        expect($management->subscription('tenant-d')?->discount?->code)->toBe('SAVE20')
            ->and($management->subscription('tenant-d')?->organizationRef)->toBe('tenant-d')
            ->and($management->previewChange('tenant-d', 'pro')->discount?->isForever())->toBeTrue()
            ->and($management->changePlan('tenant-d', 'pro')->discount?->percentOff)->toBe(20)
            ->and($management->subscription('tenant-d')?->discount?->code)->toBe('SAVE20');
    });

    it('pages the change feed and keys each row on the caller\'s org', function (): void {
        $base = new DateTimeImmutable('2026-10-01T00:00:00+00:00');

        foreach (['tenant-1', 'tenant-2', 'tenant-3'] as $i => $org) {
            $this->managementTransport()->withSubscription(
                $org,
                new Subscription('pro', 'active', $base, $base->modify('+1 month'), $base->modify('+1 month')),
                $base->modify('+'.($i + 1).' days'),
            );
        }

        $management = $this->makeBillingManagement();

        $first = $management->subscriptions(limit: 2);

        expect(array_map(static fn (SubscriptionChange $row): string => $row->organizationKey(), $first->data))->toBe(['tenant-1', 'tenant-2'])
            ->and($first->hasMore)->toBeTrue()
            ->and($first->nextCursor)->not->toBeNull()
            ->and($first->data[0]->organizationId)->not->toBe('tenant-1');

        $second = $management->subscriptions(cursor: $first->nextCursor, limit: 2);

        expect(array_map(static fn (SubscriptionChange $row): ?string => $row->organizationRef, $second->data))->toBe(['tenant-3'])
            ->and($second->hasMore)->toBeFalse()
            ->and($second->nextCursor)->toBeNull();

        $since = $management->subscriptions(updatedSince: $base->modify('+2 days'));

        expect(array_map(static fn (SubscriptionChange $row): ?string => $row->organizationRef, $since->data))->toBe(['tenant-2', 'tenant-3']);
    });

    it('drains every page lazily', function (): void {
        $base = new DateTimeImmutable('2026-10-01T00:00:00+00:00');

        foreach (range(1, 5) as $i) {
            $this->managementTransport()->withSubscription("tenant-{$i}", new Subscription('pro', 'active'), $base->modify("+{$i} hours"));
        }

        $all = iterator_to_array($this->makeBillingManagement()->allSubscriptions(pageSize: 2));

        expect(array_map(static fn (SubscriptionChange $row): ?string => $row->organizationRef, $all))
            ->toBe(['tenant-1', 'tenant-2', 'tenant-3', 'tenant-4', 'tenant-5']);
    });

    it('lists a subscription canceled outright in the feed', function (): void {
        $management = $this->makeBillingManagement();
        $management->subscribe('tenant-1', 'pro');
        $management->cancel('tenant-1', atPeriodEnd: false);

        $row = $management->subscriptions()->data[0];

        expect($row->status)->toBe('canceled')
            ->and($row->canceledAt)->not->toBeNull()
            ->and($row->cancelAtPeriodEnd)->toBeFalse();
    });
});

it('refuses the 0.4.2 calls clearly on a transport written before them', function (): void {
    $management = new BillingManagement(new class implements ManagementTransport
    {
        public function plans(?string $currency = null): array
        {
            return [];
        }

        public function ensureOrganization(string $org, array $attributes): void {}

        public function subscription(string $org): ?Subscription
        {
            return null;
        }

        public function subscribe(string $org, string $plan): SubscriptionResult
        {
            throw new LogicException('unused');
        }

        public function previewChange(string $org, string $plan): ChangePreview
        {
            throw new LogicException('unused');
        }

        public function changePlan(string $org, string $plan): ChangePreview
        {
            throw new LogicException('unused');
        }

        public function cancel(string $org, bool $atPeriodEnd): Subscription
        {
            throw new LogicException('unused');
        }

        public function usage(string $org): UsageSummary
        {
            throw new LogicException('unused');
        }

        public function invoices(string $org): array
        {
            return [];
        }

        public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null): CheckoutSession
        {
            throw new LogicException('unused');
        }

        public function createPortalSession(string $org, string $returnUrl): PortalSession
        {
            throw new LogicException('unused');
        }

        public function createSetupIntent(string $org): SetupIntent
        {
            throw new LogicException('unused');
        }

        public function createPaymentIntent(string $org, ?string $reference = null, ?int $amountMinor = null, ?string $currency = null): PaymentIntent
        {
            throw new LogicException('unused');
        }

        public function paymentMethods(string $org): array
        {
            return [];
        }

        public function setDefaultPaymentMethod(string $org, string $id): void {}

        public function removePaymentMethod(string $org, string $id): void {}
    });

    expect(fn () => $management->reactivate('org_a'))->toThrow(UnsupportedOperation::class, 'reactivate() needs a transport that implements')
        ->and(fn () => $management->subscriptions())->toThrow(UnsupportedOperation::class)
        ->and(fn () => $management->allSubscriptions())->toThrow(UnsupportedOperation::class)
        ->and(fn () => $management->bundles('org_a'))->toThrow(UnsupportedOperation::class, 'bundles() needs a transport that implements')
        ->and(fn () => $management->previewCancel('org_a'))->toThrow(UnsupportedOperation::class)
        // The 0.4.1 surface keeps working on it.
        ->and($management->plans())->toBe([]);
});
