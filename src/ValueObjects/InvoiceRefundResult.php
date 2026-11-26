<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * The answer to a refund: `status` is `refunded` (with `refundId`) or `pending_approval` (with
 * `approvalId`, the approval request's public id, `blapr_…`; `apr_…` before 2026-11-26).
 */
readonly class InvoiceRefundResult
{
    public function __construct(public string $status, public ?string $refundId = null, public ?string $approvalId = null) {}
}
