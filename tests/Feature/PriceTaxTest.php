<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Enums\TaxBasis;
use Cbox\Billing\Client\Enums\TaxStatus;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PriceTax;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Billing tells an app what its customer pays: the tax on every plan for the buyer it names, on a
 * subscription's recurring charge, on a plan change, and on an invoice. An answer the client cannot
 * read is no answer, never a guessed price.
 */
function taxedHttp(): HttpManagementTransport
{
    return new HttpManagementTransport(app(HttpFactory::class), 'https://billing.test', 'secret-token');
}

/** @return array<string, mixed> */
function wireTax(string $status = 'calculated', ?int $tax = 975, ?int $total = 4875, string $treatment = 'standard', ?string $rate = '25'): array
{
    return ['status' => $status, 'basis' => 'customer', 'country' => 'DK', 'customer' => 'business', 'treatment' => $treatment, 'rate' => $rate, 'tax_minor' => $tax, 'total_minor' => $total, 'reason' => null];
}

it('reads the tax on each plan and asks for the buyer it names', function (): void {
    Http::fake([
        'billing.test/api/v1/plans*' => Http::response([
            'currency' => 'DKK',
            'data' => [
                ['key' => 'std', 'name' => 'Standard', 'interval' => 'month', 'entitlements' => [], 'price' => ['minor' => 3_900, 'currency' => 'DKK', 'tax_inclusive' => false], 'tax' => wireTax()],
                ['key' => 'rc', 'name' => 'Reverse', 'interval' => 'month', 'entitlements' => [], 'price' => ['minor' => 3_900, 'currency' => 'DKK'], 'tax' => wireTax(tax: 0, total: 3_900, treatment: 'reverse_charge', rate: null)],
                ['key' => 'down', 'name' => 'Down', 'interval' => 'month', 'entitlements' => [], 'price' => ['minor' => 3_900, 'currency' => 'DKK'], 'tax' => ['status' => 'unavailable', 'basis' => 'seller_default', 'country' => 'DK', 'customer' => 'consumer', 'treatment' => null, 'rate' => null, 'tax_minor' => null, 'total_minor' => null, 'reason' => 'tax_calculation_unavailable']],
                ['key' => 'odd', 'name' => 'Odd', 'interval' => 'month', 'entitlements' => [], 'price' => ['minor' => 3_900, 'currency' => 'DKK'], 'tax' => ['status' => 'guessed', 'basis' => 'customer']],
                ['key' => 'old', 'name' => 'Old', 'interval' => 'month', 'entitlements' => [], 'price' => ['minor' => 3_900, 'currency' => 'DKK']],
            ],
        ]),
    ]);

    [$standard, $reverse, $down, $odd, $old] = (new BillingManagement(taxedHttp()))->plans('dkk', 'org_1', 'se');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/plans?currency=DKK&org=org_1&country=SE');

    expect($standard->tax?->status)->toBe(TaxStatus::Calculated)
        ->and($standard->tax?->basis)->toBe(TaxBasis::Customer)
        ->and($standard->tax?->rate)->toBe('25')
        ->and($standard->grossMinor())->toBe(4_875)
        ->and($standard->tax?->chargesTax())->toBeTrue()
        ->and($reverse->tax?->isReverseCharge())->toBeTrue()
        ->and($reverse->grossMinor())->toBe(3_900)
        ->and($reverse->tax?->chargesTax())->toBeFalse()
        ->and($down->tax?->status)->toBe(TaxStatus::Unavailable)
        ->and($down->tax?->reason)->toBe('tax_calculation_unavailable')
        ->and($down->grossMinor())->toBeNull()
        ->and($odd->tax)->toBeNull()
        ->and($old->tax)->toBeNull()
        ->and($old->grossMinor())->toBeNull();
});

it('reads a subscription recurring price, a change preview tax and an invoice net', function (): void {
    Http::fake([
        'billing.test/api/v1/subscriptions/org_1/preview' => Http::response([
            'due_now_minor' => 2_500, 'due_now_net_minor' => 2_000, 'due_now_tax_minor' => 500, 'credit_minor' => 0, 'new_recurring_minor' => 9_900,
            'effective_at' => '2026-11-01T00:00:00+00:00', 'lines' => [], 'discount' => null,
            'due_now_tax' => wireTax(tax: 500, total: 2_500), 'new_recurring_tax' => wireTax(tax: 2_475, total: 12_375),
        ]),
        'billing.test/api/v1/subscriptions/org_1' => Http::response([
            'plan' => 'std', 'status' => 'active', 'recurring' => ['net_minor' => 3_900, 'currency' => 'DKK', 'tax' => wireTax()],
        ]),
        'billing.test/api/v1/invoices/org_1' => Http::response(['data' => [
            ['number' => 'CB-1', 'date' => null, 'amount_minor' => 4_875, 'subtotal_minor' => 3_900, 'tax_minor' => 975, 'currency' => 'DKK', 'status' => 'paid'],
            ['number' => 'CB-0', 'date' => null, 'amount_minor' => 100, 'currency' => 'DKK', 'status' => 'paid'],
        ]]),
    ]);

    $transport = taxedHttp();
    $subscription = $transport->subscription('org_1');
    $preview = $transport->previewChange('org_1', 'pro');
    [$new, $old] = $transport->invoices('org_1');

    expect($subscription?->recurring?->netMinor)->toBe(3_900)
        ->and($subscription?->recurring?->totalMinor())->toBe(4_875)
        ->and($preview->dueNowMinor)->toBe(2_500)
        ->and($preview->dueNowNetMinor)->toBe(2_000)
        ->and($preview->dueNowTaxMinor)->toBe(500)
        ->and($preview->dueNowTax?->totalMinor)->toBe(2_500)
        ->and($preview->newRecurringTax?->totalMinor)->toBe(12_375)
        ->and($new->subtotalMinor)->toBe(3_900)
        ->and($new->taxMinor)->toBe(975)
        ->and($old->subtotalMinor)->toBeNull()
        ->and($old->taxMinor)->toBeNull();
});

it('refuses to name a buyer through a transport that cannot', function (): void {
    $transport = Mockery::mock(ManagementTransport::class);
    $transport->shouldReceive('plans')->with('DKK')->andReturn([]);
    $management = new BillingManagement($transport);

    expect($management->plans('DKK'))->toBe([]);
    $management->plans('DKK', 'org_1');
})->throws(UnsupportedOperation::class);

it('fakes the tax billing answers for the buyer it resolves', function (): void {
    $fake = (new FakeManagementTransport)
        ->withPlan(new Plan('std', 'Standard', 3_900, 'DKK', 'month', []))
        ->withPlan(new Plan('pro', 'Pro', 9_900, 'DKK', 'month', []))
        ->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::SellerDefault, 'DK', 'consumer', 'standard', '25'))
        ->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::Customer, 'DE', 'business', 'reverse_charge'), org: 'org_de')
        ->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::Country, 'SE', 'consumer', 'standard', '25'), country: 'SE')
        ->withSubscription('org_de', new Subscription('std', 'active'))
        ->withInvoice('org_de', new Invoice('CB-1', null, 4_875, 'DKK', 'paid', 3_900, 975));
    $billing = new BillingManagement($fake);

    expect($billing->plans('DKK')[0]->grossMinor())->toBe(4_875)
        ->and($billing->plans('DKK', country: 'se')[0]->tax?->basis)->toBe(TaxBasis::Country)
        ->and($billing->plans('DKK', 'org_de')[0]->grossMinor())->toBe(3_900)
        ->and($billing->plans('DKK', 'org_de')[0]->tax?->isReverseCharge())->toBeTrue()
        // An organization nothing was seeded for gets nobody's answer, as billing falls back.
        ->and($billing->plans('DKK', 'org_other')[0]->tax?->basis)->toBe(TaxBasis::SellerDefault)
        ->and($billing->subscription('org_de')?->recurring?->totalMinor())->toBe(3_900)
        ->and($billing->previewChange('org_de', 'pro')->newRecurringTax?->totalMinor)->toBe(9_900)
        ->and($billing->invoices('org_de')[0]->taxMinor)->toBe(975);

    $fake->taxUnavailable();

    expect($billing->plans('DKK')[0]->tax?->status)->toBe(TaxStatus::Unavailable)
        ->and($billing->plans('DKK')[0]->grossMinor())->toBeNull();
});

it('sends no tax from a fake nothing was seeded for, as an older server', function (): void {
    $billing = new BillingManagement((new FakeManagementTransport)->withPlan(new Plan('std', 'Standard', 3_900, 'DKK', 'month', [])));

    expect($billing->plans('DKK')[0]->tax)->toBeNull();
});
