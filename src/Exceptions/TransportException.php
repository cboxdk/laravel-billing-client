<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Exceptions;

use Cbox\Billing\Client\Enums\FailurePolicy;
use Throwable;

/**
 * An INFRASTRUCTURE fault talking to billing — a network error, a timeout, a non-2xx
 * response, or a malformed body. It is NOT a decision: the caller's
 * {@see FailurePolicy} decides whether an affected request
 * fails open or closed. Deny-by-default parsing raises this rather than trusting a
 * malformed payload.
 */
class TransportException extends BillingClientException
{
    public static function status(string $endpoint, int $status): self
    {
        return new self("Billing transport [{$endpoint}] returned HTTP {$status}.");
    }

    public static function malformed(string $endpoint, string $detail): self
    {
        return new self("Billing transport [{$endpoint}] returned a malformed response: {$detail}.");
    }

    public static function unreachable(string $endpoint, Throwable $previous): self
    {
        return new self("Billing transport [{$endpoint}] is unreachable: {$previous->getMessage()}.", 0, $previous);
    }
}
