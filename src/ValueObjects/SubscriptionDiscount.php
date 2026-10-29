<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * The coupon discount bound to a subscription, as the management API presents it on every
 * subscription payload and on a change preview: the coupon `code` and display `name`, either a
 * `percentOff` (a percentage coupon) or an `amountOffMinor` in `currency` (a fixed one), its
 * `duration` (`once`, `repeating` or `forever`), and `endsAt`, when it stops applying (null for
 * a `forever` coupon). Amounts are integer minor units.
 */
readonly class SubscriptionDiscount
{
    public function __construct(
        public string $code,
        public string $duration,
        public ?string $name = null,
        public ?int $percentOff = null,
        public ?int $amountOffMinor = null,
        public ?string $currency = null,
        public ?DateTimeImmutable $endsAt = null,
    ) {}

    /** True for a percentage coupon, false for a fixed-amount one. */
    public function isPercentage(): bool
    {
        return $this->percentOff !== null;
    }

    /** True when the discount never lapses. */
    public function isForever(): bool
    {
        return $this->duration === 'forever';
    }
}
