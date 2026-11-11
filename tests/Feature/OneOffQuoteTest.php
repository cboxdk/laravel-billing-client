<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeOneOffQuoteTransport;
use Cbox\Billing\Client\ValueObjects\OneOffQuote;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('uses the durable quote accept payment and compensation contracts without supplying a price', function (): void {
    $quote = ['id' => 'quote_1', 'organization' => 'org_1', 'reference' => 'order:1', 'product' => 'domains.register.com', 'quantity' => 1, 'currency' => 'DKK', 'net_minor' => 11900, 'tax_minor' => 2975, 'total_minor' => 14875, 'price_version' => 'version-1', 'expires_at' => '2026-11-05T12:00:00Z', 'status' => 'open', 'invoice' => null, 'payment_status' => 'not_started'];
    Http::fake([
        'billing.test/api/v1/one-off-quotes' => Http::response($quote, 201),
        'billing.test/api/v1/one-off-quotes/quote_1/accept' => Http::response([...$quote, 'status' => 'accepted', 'invoice' => 'INV-1', 'payment_status' => 'unpaid']),
        'billing.test/api/v1/one-off-quotes/quote_1/payment-intent' => Http::response(['gateway' => 'stripe', 'publishable_key' => 'pk_test', 'client_secret' => 'cs_test', 'status' => 'requires_payment_method', 'reference' => 'INV-1']),
        'billing.test/api/v1/one-off-quotes/quote_1/cancel' => Http::response([...$quote, 'status' => 'cancelled', 'payment_status' => 'void']),
        'billing.test/api/v1/invoices/org_1/INV-1/refunds' => Http::response(['status' => 'pending_approval', 'approval' => 'apr_3VbN7mQ2xLk9JhG4fD1sZa'], 202),
        'billing.test/api/v1/one-off-quotes/*' => Http::response([...$quote, 'invoice' => 'INV-1', 'payment_status' => 'paid']),
    ]);
    $client = new BillingManagement(new HttpManagementTransport(app(Factory::class), 'https://billing.test/api/v1', 'test-secret'));
    expect($client->createOneOffQuote('org_1', 'order:1', 'domains.register.com', 'create-1', 'DKK')->totalMinor)->toBe(14875);
    expect($client->acceptOneOffQuote('org_1', 'quote_1', 'accept-1')->invoice)->toBe('INV-1');
    expect($client->createOneOffQuotePaymentIntent('org_1', 'quote_1')->clientSecret)->toBe('cs_test');
    expect($client->oneOffQuote('org_1', 'quote_1')->paymentStatus)->toBe('paid');
    expect($client->oneOffQuoteByReference('org_1', 'order:1')->reference)->toBe('order:1');
    expect($client->reconcileOneOffQuote('org_1', 'quote_1')->paymentStatus)->toBe('paid');
    expect($client->cancelOneOffQuote('org_1', 'quote_1', 'cancel-1')->paymentStatus)->toBe('void');
    expect($client->refundInvoice('org_1', 'INV-1', 'refund-1', 'service_issue')->approvalId)->toBe('apr_3VbN7mQ2xLk9JhG4fD1sZa');
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Idempotency-Key', 'create-1') && $r['reference'] === 'order:1' && ! isset($r['amount_minor']));
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Idempotency-Key', 'cancel-1') && $r['org'] === 'org_1');
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Idempotency-Key', 'refund-1') && $r['reason'] === 'service_issue');
});

it('keeps scripted quote answers within their organization', function (): void {
    $quote = new OneOffQuote('q1', 'org1', 'r1', 'domains.register.com', 1, 'DKK', 11900, 2975, 14875, 'v1', new DateTimeImmutable('+15 minutes'), 'open', null, 'not_started');
    $fake = (new FakeOneOffQuoteTransport)->withQuote($quote);
    $client = new BillingManagement($fake);
    expect($client->createOneOffQuote('org1', 'r1', 'domains.register.com', 'k1'))->toBe($quote);
    expect(fn () => $client->oneOffQuote('org2', 'q1'))->toThrow(TransportException::class);
});
