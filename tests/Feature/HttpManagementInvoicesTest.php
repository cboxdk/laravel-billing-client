<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\InvoiceAppendix;
use Cbox\Billing\Client\ValueObjects\IssuedInvoice;
use Cbox\Billing\Client\ValueObjects\NewInvoice;
use Cbox\Billing\Client\ValueObjects\NewInvoiceLine;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function invoiceDetailFixture(): array
{
    $raw = file_get_contents(__DIR__.'/../Fixtures/server/invoice-detail.json');
    $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> */
    return $decoded['body'];
}

it('creates a manual invoice with the idempotency key and maps the detail', function (): void {
    Http::fake(['billing.test/api/v1/invoices/org_a' => Http::response(invoiceDetailFixture(), 201)]);

    $issued = makeHttpManagement()->createInvoice(
        'org_a',
        new NewInvoice([new NewInvoiceLine('Consulting', '12.500', 120000, 't')], 'PO-1', null, ['work_invoice_id' => 'inv_1']),
        'inv_1',
    );

    expect($issued)->toBeInstanceOf(IssuedInvoice::class)
        ->and($issued->number)->toBe('INV-1001')
        ->and($issued->id)->toBe('inv_7Hq2kV9xTq3LmN0pR4sW1z')
        ->and($issued->amountMinor)->toBe(1875000)
        ->and($issued->subtotalMinor)->toBe(1500000)
        ->and($issued->taxMinor)->toBe(375000)
        ->and($issued->environment)->toBe('live')
        ->and($issued->lines)->toHaveCount(1)
        ->and($issued->lines[0]->quantity)->toBe('12.500')
        ->and($issued->lines[0]->unit)->toBe('t')
        ->and($issued->lines[0]->netMinor)->toBe(1500000)
        ->and($issued->appendix['title'] ?? null)->toBe('Specification')
        ->and($issued->metadata)->toBe(['work_invoice_id' => 'inv_1'])
        ->and($issued->pdfUrl)->toStartWith('https://');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->hasHeader('Idempotency-Key', 'inv_1')
        && $r->hasHeader('Authorization', 'Bearer secret-token')
        && $r['lines'][0]['quantity'] === '12.500'
        && $r['lines'][0]['unit_amount_minor'] === 120000
        && $r['order_reference'] === 'PO-1'
        && ! isset($r['appendix'])
        && $r['metadata']['work_invoice_id'] === 'inv_1');
});

it('sends the appendix', function (): void {
    Http::fake(['billing.test/api/v1/invoices/org_a' => Http::response(invoiceDetailFixture(), 201)]);

    $appendix = new InvoiceAppendix('Specification', [['key' => 'hours', 'label' => 'Hours', 'align' => 'right']], [['1,00']], ['1,00']);
    makeHttpManagement()->createInvoice('org_a', new NewInvoice([new NewInvoiceLine('x', '1', 100)], appendix: $appendix), 'k');

    Http::assertSent(fn (Request $r): bool => $r['appendix']['title'] === 'Specification'
        && $r['appendix']['rows'] === [['1,00']]
        && $r['appendix']['footer'] === ['1,00']);
});

it('reads one invoice by number', function (): void {
    Http::fake(['billing.test/api/v1/invoices/org_a/INV-1001' => Http::response(invoiceDetailFixture(), 200)]);

    $issued = makeHttpManagement()->invoice('org_a', 'INV-1001');

    expect($issued->number)->toBe('INV-1001')->and($issued->lines[0]->amountMinor)->toBe(1875000);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === 'https://billing.test/api/v1/invoices/org_a/INV-1001');
});

it('turns a 409 into BillingRefused with the server error code', function (): void {
    Http::fake(['billing.test/api/v1/invoices/org_a' => Http::response(['error' => 'tax_pending', 'message' => 'Jurisdiction unresolved'], 409)]);

    try {
        makeHttpManagement()->createInvoice('org_a', new NewInvoice([new NewInvoiceLine('x', '1', 100)]), 'k');
        $this->fail('expected a refusal');
    } catch (BillingRefused $e) {
        expect($e->status)->toBe(409)
            ->and(json_decode((string) $e->body, true)['error'])->toBe('tax_pending');
    }
});

it('turns a 422 billing_address_required into BillingRefused carrying missing fields', function (): void {
    Http::fake(['billing.test/api/v1/invoices/org_a' => Http::response(['error' => 'billing_address_required', 'missing' => ['address_line1', 'postal_code']], 422)]);

    try {
        makeHttpManagement()->createInvoice('org_a', new NewInvoice([new NewInvoiceLine('x', '1', 100)]), 'k');
        $this->fail('expected a refusal');
    } catch (BillingRefused $e) {
        expect($e->status)->toBe(422)
            ->and(json_decode((string) $e->body, true)['missing'])->toBe(['address_line1', 'postal_code']);
    }
});

it('carries the public id of each listed invoice', function (): void {
    Http::fake(['billing.test/api/v1/invoices/org_a*' => Http::response(['data' => [
        ['id' => 'inv_7Hq2kV9xTq3LmN0pR4sW1z', 'number' => 'CB-2', 'date' => '2026-11-01T00:00:00+00:00', 'amount_minor' => 0, 'currency' => 'EUR', 'status' => 'paid'],
        // An older server sent no id.
        ['number' => 'CB-1', 'date' => '2026-10-01T00:00:00+00:00', 'amount_minor' => 4900, 'currency' => 'EUR', 'status' => 'paid'],
    ], 'has_more' => false, 'next_cursor' => null])]);

    $invoices = makeHttpManagement()->invoices('org_a');

    expect($invoices[0]->id)->toBe('inv_7Hq2kV9xTq3LmN0pR4sW1z')
        ->and($invoices[0]->number)->toBe('CB-2')
        ->and($invoices[1]->id)->toBeNull();
});

it('lists an id for every invoice in the fake: the seeded one, or a stable stand-in', function (): void {
    $this->managementTransport()
        ->withInvoice('org_a', new Invoice('CB-1', null, 4_900, 'EUR', 'paid', id: 'inv_seeded'))
        ->withInvoice('org_a', new Invoice('CB-2', null, 4_900, 'EUR', 'open'));

    $first = $this->makeBillingManagement()->invoices('org_a');
    $again = $this->makeBillingManagement()->invoices('org_a');

    expect($first[0]->id)->toBe('inv_seeded')
        ->and($first[1]->id)->toStartWith('inv_')
        ->and($first[1]->id)->toBe($again[1]->id);
});
