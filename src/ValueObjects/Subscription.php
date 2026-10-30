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
 *
 * Since 0.4.4 it carries `trialEndsAt`, a scheduled plan change (`pendingChange`), and the
 * cross-product `bundle` benefit on it ({@see SubscriptionBundle}, null when no bundle discounts
 * it). An older server omits them: they read as null.
 *
 * Since 0.4.6 a subscription read (`subscription()`) carries `recurring`: what it costs per
 * period with the tax ({@see RecurringPrice}). Null from an older server, and on the responses
 * that do not price it (subscribe, change, cancel, the change feed).
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
        public ?DateTimeImmutable $trialEndsAt = null,
        public ?PendingChange $pendingChange = null,
        public ?SubscriptionBundle $bundle = null,
        public ?RecurringPrice $recurring = null,
    ) {}

    /** True while the subscription is in a free trial. */
    public function isTrialing(): bool
    {
        return $this->status === 'trialing';
    }

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
