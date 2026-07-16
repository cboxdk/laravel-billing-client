<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * A hosted-checkout session from the management API (`POST /api/v1/checkout-sessions`):
 * the `url` the product app redirects the user to so billing collects payment on its own
 * pages, and `expiresAt` — the moment the session link stops being valid. Sessions are
 * short-lived; mint a fresh one per attempt rather than caching the URL.
 */
readonly class CheckoutSession
{
    public function __construct(
        public string $url,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * True when the session's expiry has passed relative to `$now` (defaults to the
     * current time). A session with no known expiry is treated as still open.
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= ($now ?? new DateTimeImmutable);
    }
}
