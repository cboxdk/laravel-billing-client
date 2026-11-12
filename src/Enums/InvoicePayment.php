<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

/**
 * What the off-session charge of a subscribe or an immediate plan change did with its invoice
 * (0.6.3).
 *
 *  - `Paid`: the charge went through.
 *  - `Pending`: the invoice is still owed and the customer has to act: complete 3-D Secure,
 *    replace a declined card, or pay by bank transfer. Send them to a portal session with
 *    {@see PortalSection::PendingPayment} and the invoice's id.
 *  - `NoneDue`: nothing was left to collect (a free plan, a 100% promo code, account credit), so
 *    the invoice was settled when it was issued.
 */
enum InvoicePayment: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case NoneDue = 'none_due';
}
