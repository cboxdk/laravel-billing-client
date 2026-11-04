<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\InvoiceRefundResult;
use Cbox\Billing\Client\ValueObjects\OneOffQuote;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;

/** Additive standalone quote, payment and compensation contract. */
interface OneOffQuoteTransport
{
    public function createOneOffQuote(string $org, string $reference, string $product, string $idempotencyKey, ?string $currency = null, int $quantity = 1): OneOffQuote;

    public function oneOffQuote(string $org, string $id): OneOffQuote;

    public function oneOffQuoteByReference(string $org, string $reference): OneOffQuote;

    public function acceptOneOffQuote(string $org, string $id, string $idempotencyKey): OneOffQuote;

    public function reconcileOneOffQuote(string $org, string $id): OneOffQuote;

    public function cancelOneOffQuote(string $org, string $id, string $idempotencyKey): OneOffQuote;

    public function createOneOffQuotePaymentIntent(string $org, string $id): PaymentIntent;

    public function refundInvoice(string $org, string $number, string $idempotencyKey, string $reason = 'requested', ?int $amountMinor = null): InvoiceRefundResult;
}
