<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Exceptions;

use Cbox\Billing\Client\Enums\FailurePolicy;

/**
 * A SEMANTIC hard-limit refusal: the organization's remaining allowance for the
 * meter is exhausted centrally (billing granted zero on refill), so no local lease
 * can satisfy the request. This always fails closed, independent of the deployment's
 * {@see FailurePolicy} — an exhausted allowance is a
 * decision, not an outage.
 */
class QuotaExceeded extends BillingClientException
{
    public function __construct(
        public readonly string $org,
        public readonly string $meter,
        public readonly int $requested,
    ) {
        parent::__construct("Allowance exhausted for meter [{$meter}] on org [{$org}]: cannot reserve {$requested} unit(s).");
    }
}
