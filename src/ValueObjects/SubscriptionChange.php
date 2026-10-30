<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * One row of the subscription change feed (`GET /api/v1/subscriptions`): a subscription as it
 * stood at `updatedAt`, so an app can mirror every subscriber's plan and status without polling
 * each organization.
 *
 * `id` is Billing's subscription id and `organizationId` is Billing's organization id. Key your
 * own records on `organizationRef`, your tenant key for the organization (the `{org}` you call
 * the other endpoints with), or on {@see organizationKey()}, which falls back to Billing's id for
 * an organization provisioned without one. `plan` and `product` are keys, null when the plan was
 * removed. `status` uses the same words as {@see Subscription::$status}.
 *
 * Since 0.4.3 it carries the `reference` (what inside the organization it is for, such as a Sites
 * website; null for the organization itself) and the `bundle` benefit on it.
 */
readonly class SubscriptionChange
{
    public function __construct(
        public int $id,
        public string $organizationId,
        public ?string $organizationRef,
        public ?string $plan,
        public ?string $product,
        public string $status,
        public bool $paused = false,
        public ?string $pauseReason = null,
        public int $seats = 1,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public bool $cancelAtPeriodEnd = false,
        public ?DateTimeImmutable $trialEndsAt = null,
        public ?DateTimeImmutable $canceledAt = null,
        public ?SubscriptionDiscount $discount = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $reference = null,
        public ?SubscriptionBundle $bundle = null,
    ) {}

    /** Your tenant key for the organization, or Billing's organization id when it has none. */
    public function organizationKey(): string
    {
        return $this->organizationRef ?? $this->organizationId;
    }

    /** True when access and metering are suspended until the subscription is resumed. */
    public function isPaused(): bool
    {
        return $this->paused || $this->status === 'paused';
    }
}
