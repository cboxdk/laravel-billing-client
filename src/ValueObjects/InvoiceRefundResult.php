<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

readonly class InvoiceRefundResult
{
    public function __construct(public string $status, public ?string $refundId = null, public ?int $approvalId = null) {}
}
