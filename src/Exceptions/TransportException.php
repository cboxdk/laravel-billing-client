<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Exceptions;

use Cbox\Billing\Client\Enums\FailurePolicy;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Any fault talking to billing. Since 0.4.0 it is the PARENT of two causes, and code that
 * still catches this class keeps catching both:
 *
 *  - {@see BillingUnavailable} — billing could not answer: a connection error, a timeout, a
 *    5xx, a 429, or a malformed body. An INFRASTRUCTURE fault: the caller's
 *    {@see FailurePolicy} decides whether an affected request fails open or closed.
 *  - {@see BillingRefused} — billing answered and said no: a 4xx other than 429 (a revoked
 *    token, a forbidden org, a validation error, an unknown plan). A DECISION, so it always
 *    fails closed, whatever the policy.
 *
 * Each carries the endpoint, the HTTP `status` (null when no response arrived), the raw
 * response `body`, and the `Retry-After` hint in seconds when the server sent one.
 */
class TransportException extends BillingClientException
{
    public function __construct(
        string $message,
        public readonly string $endpoint = '',
        public readonly ?int $status = null,
        public readonly ?string $body = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * Classify a non-2xx response: a 4xx other than 408/429 is a {@see BillingRefused};
     * everything else (5xx, 408, 429, an unexpected 1xx/3xx) is {@see BillingUnavailable}.
     */
    public static function fromResponse(string $endpoint, Response $response): self
    {
        return self::status(
            $endpoint,
            $response->status(),
            $response->body(),
            self::parseRetryAfter($response->header('Retry-After')),
        );
    }

    /**
     * Classify a bare status. Kept for 0.3 callers (the fakes used it); it now returns the
     * subclass that matches the status instead of the undifferentiated parent.
     */
    public static function status(string $endpoint, int $status, ?string $body = null, ?int $retryAfter = null): self
    {
        $message = "Billing transport [{$endpoint}] returned HTTP {$status}.";

        if (self::isRefusal($status)) {
            return new BillingRefused($message, $endpoint, $status, $body, $retryAfter);
        }

        return new BillingUnavailable($message, $endpoint, $status, $body, $retryAfter);
    }

    /** A 2xx whose body could not be read. Billing did not give a usable answer: unavailable. */
    public static function malformed(string $endpoint, string $detail): self
    {
        return new BillingUnavailable("Billing transport [{$endpoint}] returned a malformed response: {$detail}.", $endpoint);
    }

    /** No response at all: a connection error or a timeout. */
    public static function unreachable(string $endpoint, Throwable $previous): self
    {
        return new BillingUnavailable(
            "Billing transport [{$endpoint}] is unreachable: {$previous->getMessage()}.",
            $endpoint,
            previous: $previous,
        );
    }

    /** True for a status billing uses to say no, as opposed to "not now". */
    public static function isRefusal(int $status): bool
    {
        return $status >= 400 && $status < 500 && $status !== 408 && $status !== 429;
    }

    /**
     * `Retry-After` is either delta-seconds or an HTTP-date (RFC 9110 §10.2.3). Returns
     * whole seconds from now, never negative; null when absent or unreadable.
     */
    public static function parseRetryAfter(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $at = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));

        if ($at === false) {
            return null;
        }

        return max(0, $at->getTimestamp() - time());
    }
}
