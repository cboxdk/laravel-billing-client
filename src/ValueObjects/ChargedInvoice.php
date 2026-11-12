<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Enums\InvoicePayment;
use Cbox\Billing\Client\Enums\PortalSection;

/**
 * The invoice a subscribe or an immediate plan change issued and charged off-session (0.6.3):
 * Billing's public `id` (`inv_…`), its `number`, `status`, `amountDueMinor` (the total less
 * account credit, in minor units of `currency`) and what the charge did, `payment`.
 *
 * When {@see isPending()}, the customer has to act: open a portal session with
 * {@see PortalSection::PendingPayment} and `invoice: $invoice->id`.
 */
readonly class ChargedInvoice
{
    public function __construct(
        public string $id,
        public ?string $number,
        public string $status,
        public int $amountDueMinor,
        public string $currency,
        public InvoicePayment $payment,
    ) {}

    /** True when the invoice is still owed and the customer must act in the portal. */
    public function isPending(): bool
    {
        return $this->payment === InvoicePayment::Pending;
    }
}
