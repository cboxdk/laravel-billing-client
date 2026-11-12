<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Enums\PortalSection;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\PortalSession;
use InvalidArgumentException;

/**
 * A portal session that opens at a page or section, added in 0.6.1. A separate contract, like
 * {@see IdempotentSessionTransport}, so a transport written against an earlier release keeps
 * compiling. {@see HttpManagementTransport} and {@see FakeManagementTransport} implement it;
 * {@see BillingManagement} throws an UnsupportedOperation when a section is asked of a transport
 * that does not.
 */
interface PortalSectionTransport
{
    /**
     * `POST /v1/portal-sessions` with `section`, and `invoice` or `subscription` for
     * {@see PortalSection::PendingPayment}. Only the fields given are sent.
     *
     * @throws InvalidArgumentException `invoice`/`subscription` without `PendingPayment`, or `PendingPayment` without either
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createPortalSession(string $org, string $returnUrl, ?string $idempotencyKey = null, ?PortalSection $section = null, ?string $invoice = null, ?string $subscription = null): PortalSession;
}
