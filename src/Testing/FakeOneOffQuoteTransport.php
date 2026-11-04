<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\OneOffQuoteTransport;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\InvoiceRefundResult;
use Cbox\Billing\Client\ValueObjects\OneOffQuote;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;

/** Script the server's answers; this fake never invents prices, tax or settlement. */
class FakeOneOffQuoteTransport extends FakeManagementTransport implements OneOffQuoteTransport
{
    /** @var array<string, OneOffQuote> */
    private array $quotes = [];

    /** @var array<string, OneOffQuote> */
    private array $accepted = [];

    /** @var array<string, PaymentIntent> */
    private array $intents = [];

    /** @var array<string, InvoiceRefundResult> */
    private array $refunds = [];

    public function withQuote(OneOffQuote $quote): static
    {
        $this->quotes[$quote->organization.'|'.$quote->id] = $quote;

        return $this;
    }

    public function withAcceptedQuote(OneOffQuote $quote): static
    {
        $this->accepted[$quote->organization.'|'.$quote->id] = $quote;

        return $this;
    }

    public function withQuotePayment(string $org, string $id, PaymentIntent $intent): static
    {
        $this->intents[$org.'|'.$id] = $intent;

        return $this;
    }

    public function withInvoiceRefund(string $org, string $number, InvoiceRefundResult $result): static
    {
        $this->refunds[$org.'|'.$number] = $result;

        return $this;
    }

    public function createOneOffQuote(string $org, string $reference, string $product, string $idempotencyKey, ?string $currency = null, int $quantity = 1): OneOffQuote
    {
        $quote = $this->oneOffQuoteByReference($org, $reference);
        if ($quote->product !== $product || $quote->quantity !== $quantity || ($currency !== null && $quote->currency !== $currency)) {
            throw TransportException::malformed('fake quote', 'request differs from seeded quote');
        }

        return $quote;
    }

    public function oneOffQuote(string $org, string $id): OneOffQuote
    {
        return $this->quotes[$org.'|'.$id] ?? throw TransportException::malformed('fake quote', 'no answer seeded for organization and quote');
    }

    public function oneOffQuoteByReference(string $org, string $reference): OneOffQuote
    {
        foreach ($this->quotes as $quote) {
            if ($quote->organization === $org && $quote->reference === $reference) {
                return $quote;
            }
        }
        throw TransportException::malformed('fake quote', 'no answer seeded for organization and reference');
    }

    public function acceptOneOffQuote(string $org, string $id, string $idempotencyKey): OneOffQuote
    {
        $quote = $this->accepted[$org.'|'.$id] ?? throw TransportException::malformed('fake quote', 'no accepted answer seeded');
        $this->withQuote($quote);

        return $quote;
    }

    public function reconcileOneOffQuote(string $org, string $id): OneOffQuote
    {
        return $this->oneOffQuote($org, $id);
    }

    public function cancelOneOffQuote(string $org, string $id, string $idempotencyKey): OneOffQuote
    {
        $quote = $this->oneOffQuote($org, $id);
        if ($quote->status !== 'cancelled') {
            throw TransportException::malformed('fake cancel', 'no cancelled answer seeded');
        }

        return $quote;
    }

    public function createOneOffQuotePaymentIntent(string $org, string $id): PaymentIntent
    {
        return $this->intents[$org.'|'.$id] ?? throw TransportException::malformed('fake payment', 'no payment answer seeded');
    }

    public function refundInvoice(string $org, string $number, string $idempotencyKey, string $reason = 'requested', ?int $amountMinor = null): InvoiceRefundResult
    {
        return $this->refunds[$org.'|'.$number] ?? throw TransportException::malformed('fake refund', 'no refund answer seeded');
    }
}
