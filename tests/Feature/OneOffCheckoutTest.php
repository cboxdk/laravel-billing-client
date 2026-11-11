<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\OneOffCheckoutItem;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('supports standalone orders and redacted idempotent replay through the official transport', function (): void {
    $fake = (new FakeManagementTransport)->withOneOffProduct('domain-registration');
    $client = new BillingManagement($fake);
    $items = [new OneOffCheckoutItem('domain-registration')];
    $first = $client->createOneOffCheckoutSession('org_1', $items, 'https://app.test/done', 'order:1', 'stable-key', 'DKK');
    $replay = $client->createOneOffCheckoutSession('org_1', $items, 'https://app.test/done', 'order:1', 'stable-key', 'DKK');
    expect($first->url)->not->toBeNull()->and($replay->url)->toBeNull()
        ->and($replay->expiresAt)->toEqual($first->expiresAt);
    expect(fn () => $client->createOneOffCheckoutSession('org_1', $items, 'https://app.test/done', 'order:2', 'stable-key', 'DKK'))->toThrow(TransportException::class);
    expect($client->createOneOffCheckoutSession('org_2', $items, 'https://app.test/done', 'order:1', 'stable-key')->url)->not->toBeNull();
    expect(fn () => $client->createOneOffCheckoutSession('org_1', [new OneOffCheckoutItem('unknown')], 'https://app.test/done', 'order:3', 'other-key'))->toThrow(TransportException::class);
});

it('sends typed catalog items and the caller key unchanged and refuses malformed success', function (): void {
    Http::fake(['billing.test/*' => Http::sequence()->push(['expires_at' => '2026-11-05T12:00:00Z'])->push([])]);
    $transport = new HttpManagementTransport(app(Factory::class), 'https://billing.test/api/v1', 'test-token');
    $transport->createOneOffCheckoutSession('org_1', [new OneOffCheckoutItem('registration', 2)], 'https://app.test/done', 'order:exact', 'key:exact', 'DKK');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'key:exact')
        && $request['reference'] === 'order:exact' && $request['org'] === 'org_1'
        && $request['items'] === [['product' => 'registration', 'quantity' => 2]]
        && $request['currency'] === 'DKK' && ! isset($request['plan']));
    expect(fn () => $transport->createOneOffCheckoutSession('org_1', [new OneOffCheckoutItem('registration')], 'https://app.test/done', 'order:exact', 'key:exact'))->toThrow(TransportException::class);
});
