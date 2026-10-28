<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Exceptions;

use Cbox\Billing\Client\Enums\FailurePolicy;

/**
 * Billing could not give an answer: a connection error or timeout (`status` is null), a
 * 5xx, a 408, a 429 (load shedding — honour `retryAfter`), or a 2xx with a malformed
 * body.
 *
 * This is the ONLY error the enforcement client resolves through the
 * {@see FailurePolicy}: `allow` admits the request best-effort, `deny` surfaces this
 * exception.
 */
class BillingUnavailable extends TransportException {}
