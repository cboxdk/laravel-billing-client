<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use DateTimeInterface;

/**
 * A subscribe with a `reference` and a `billing_anchor_date`, added in 0.6.5. A separate
 * contract, like {@see OrganizationReadTransport}, so a transport written against an earlier
 * release keeps compiling. {@see HttpManagementTransport} and {@see FakeManagementTransport}
 * implement it; {@see BillingManagement} throws an UnsupportedOperation when its transport does not.
 */
interface SubscribeOptionsTransport
{
    /**
     * `POST /v1/subscriptions` with `reference` (what inside the organization it is for, such as a
     * Sites website) and `billing_anchor_date` (the UTC day it is first invoiced and renews on; it
     * serves from now and nothing is invoiced when it opens). A null leaves the field out.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function subscribeWith(string $org, string $plan, ?string $reference, ?DateTimeInterface $billingAnchorDate, ?string $idempotencyKey = null): SubscriptionResult;
}
