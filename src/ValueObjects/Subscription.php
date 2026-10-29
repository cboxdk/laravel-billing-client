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
 *
 * Since 0.4.2 it also carries the bound coupon `discount`, the `organizationRef` (your own
 * tenant key for the organization, the `{org}` you call with), the `pauseReason` (today only
 * `billing_address`) and whether it is `paused`. An older server omits them: they read as
 * null and false.
 */
readonly class Subscription
{
    public function __construct(
        public string $plan,
        public string $status,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public ?DateTimeImmutable $renewsAt = null,
        public ?SubscriptionDiscount $discount = null,
        public ?string $organizationRef = null,
        public ?string $pauseReason = null,
        public bool $paused = false,
    ) {}

    /** True when the subscription is set to lapse at period end (no renewal scheduled). */
    public function cancelsAtPeriodEnd(): bool
    {
        return $this->renewsAt === null;
    }

    /** True when access and metering are suspended until the subscription is resumed. */
    public function isPaused(): bool
    {
        return $this->paused || $this->status === 'paused';
    }
}
