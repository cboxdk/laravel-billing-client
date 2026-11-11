<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('keeps caller identities on retried subscription mutations', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions' => Http::response(['subscription' => ['plan' => 'team', 'status' => 'active']], 201),
        'billing.test/api/v1/subscriptions/org/change' => Http::response(['due_now_minor' => 0, 'credit_minor' => 0, 'new_recurring_minor' => 100, 'lines' => []]),
        'billing.test/api/v1/subscriptions/org/*' => Http::response(['plan' => 'team', 'status' => 'active']),
    ]);
    $client = new BillingManagement(new HttpManagementTransport(app(Factory::class), 'https://billing.test/api/v1', 'test-secret'));
    for ($i = 0; $i < 2; $i++) {
        $client->subscribe('org', 'team', idempotencyKey: 'subscribe-stable');
        $client->changePlan('org', 'team', idempotencyKey: 'change-stable');
        $client->cancel('org', idempotencyKey: 'cancel-stable');
        $client->reactivate('org', idempotencyKey: 'reactivate-stable');
    }
    foreach (['subscribe', 'change', 'cancel', 'reactivate'] as $action) {
        expect(Http::recorded(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $action.'-stable')))->toHaveCount(2);
    }
});

it('refuses unsupported idempotency instead of silently dropping the key', function (): void {
    $transport = Mockery::mock(ManagementTransport::class);
    $transport->shouldNotReceive('subscribe');
    expect(fn () => (new BillingManagement($transport))->subscribe('org', 'team', 'stable'))->toThrow(UnsupportedOperation::class);
});

it('refuses an empty key before contacting billing', function (): void {
    Http::fake();
    $client = new BillingManagement(new HttpManagementTransport(app(Factory::class), 'https://billing.test/api/v1', 'test-secret'));
    expect(fn () => $client->subscribe('org', 'team', ''))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});
