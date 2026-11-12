<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

/**
 * Where a portal session opens (`POST /v1/portal-sessions` `section`), so an app sends the
 * customer straight to what it asked about instead of keeping a billing form of its own.
 *
 *  - `Overview`, `Subscriptions`, `Invoices`, `Settings` — that page of the portal.
 *  - `PaymentMethods`, `BillingDetails` — Settings, at that section.
 *  - `PendingPayment` — the pay page of an invoice that waits on the customer (a 3-D Secure
 *    challenge on a plan's first charge, a declined card). Needs an `invoice` or a
 *    `subscription`. Once paid, Billing returns to the `return_url` with `billing_payment=paid`
 *    and `billing_invoice={number}` added; its back link carries `billing_payment=open`.
 *
 * Needs a Billing server that knows `section` (cbox-billing after 2026-11-12); an older one
 * ignores it and opens the overview.
 */
enum PortalSection: string
{
    case Overview = 'overview';
    case Subscriptions = 'subscriptions';
    case Invoices = 'invoices';
    case Settings = 'settings';
    case PaymentMethods = 'payment_methods';
    case BillingDetails = 'billing_details';
    case PendingPayment = 'pending_payment';
}
