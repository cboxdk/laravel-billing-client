<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * An organization's current subscription from the management API
 * (`GET /api/v1/subscriptions/{org}` and the mutating endpoints): the subscribed plan
 * `key`, its `status` (e.g. `active`, `trialing`, `past_due`, `canceled`), the current
 * period bounds, and `renewsAt` (null once the subscription is set to cancel at period
 * end). Dates are parsed to immutable values off the wire.
 */
readonly class Subscription
{
    public function __construct(
        public string $plan,
        public string $status,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public ?DateTimeImmutable $renewsAt = null,
    ) {}

    /** True when the subscription is set to lapse at period end (no renewal scheduled). */
    public function cancelsAtPeriodEnd(): bool
    {
        return $this->renewsAt === null;
    }
}
