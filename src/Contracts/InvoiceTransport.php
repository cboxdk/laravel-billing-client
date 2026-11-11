<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\IssuedInvoice;
use Cbox\Billing\Client\ValueObjects\NewInvoice;

/**
 * The manual-invoice endpoints added to the management API in 0.4.7: issue an invoice from an
 * app and read one back. A separate contract rather than new methods on
 * {@see ManagementTransport}, so a transport written against an older release keeps compiling.
 * {@see HttpManagementTransport} and {@see FakeManagementTransport} implement it;
 * {@see BillingManagement} uses it when its transport has it and otherwise throws an
 * UnsupportedOperation.
 *
 * Deny-by-default like {@see ManagementTransport}: a call that cannot complete throws a
 * {@see TransportException}.
 */
interface InvoiceTransport
{
    /**
     * Issue a manual invoice to `org` (`POST /v1/invoices/{org}`), exactly as the console's
     * "new invoice" does. The `$idempotencyKey` travels as the `Idempotency-Key` header: the same
     * key with the same body returns the same invoice, with a different body it is a 409.
     *
     * @throws TransportException a BillingRefused when billing says no (400 `idempotency_key_required`, 403 `product_bound_token`, 422 `billing_address_required` with `{error, message, missing}` or a Laravel validation failure with `{message, errors: {path: [...]}}` and no `error` code, 409 `tax_pending`, `seller_incomplete`, `invoice_refused`, `idempotency_conflict` or `idempotency_in_flight`), a BillingUnavailable when it cannot answer
     */
    public function createInvoice(string $org, NewInvoice $invoice, string $idempotencyKey): IssuedInvoice;

    /**
     * One invoice with its lines, appendix, metadata and signed `pdf_url`
     * (`GET /v1/invoices/{org}/{number}`).
     *
     * @throws TransportException a BillingRefused when billing says no (404 for an unknown number), a BillingUnavailable when it cannot answer
     */
    public function invoice(string $org, string $number): IssuedInvoice;
}
