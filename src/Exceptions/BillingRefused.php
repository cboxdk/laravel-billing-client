<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Exceptions;

use Cbox\Billing\Client\Enums\FailurePolicy;

/**
 * Billing answered and refused the call: a 4xx other than 408 and 429. A revoked or
 * wrong token (401), a forbidden organization (403), an unknown plan or organization
 * (404), a conflict (409), a validation error (422).
 *
 * This is a DECISION, not an outage, so the enforcement client never fails open on it,
 * whatever the {@see FailurePolicy}: a revoked token must not admit every request. Read
 * `status` and `body` to tell the cases apart.
 */
class BillingRefused extends TransportException {}
