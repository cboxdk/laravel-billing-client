<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The `bundle` block of a subscription: the {@see BundleBenefit} on its current period, the date
 * it ends (`endsAt`, when another subscription is set to end or change plan), and what applies
 * from `nextFrom` (`next`, null for full price). A benefit earned after the current period was
 * invoiced reads `benefit` null and `next` from the next renewal.
 */
readonly class SubscriptionBundle
{
    public function __construct(
        public ?BundleBenefit $benefit = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?BundleBenefit $next = null,
        public ?DateTimeImmutable $nextFrom = null,
    ) {}

    /** True when the current benefit is scheduled to end. */
    public function isEnding(): bool
    {
        return $this->benefit !== null && $this->endsAt !== null;
    }

    /** @return array<string, mixed> the wire shape Cbox Billing sends */
    public function toArray(): array
    {
        return [
            'benefit' => $this->benefit?->toArray(),
            'ends_at' => $this->endsAt?->format(DateTimeInterface::ATOM),
            'next' => $this->next?->toArray(),
            'next_from' => $this->nextFrom?->format(DateTimeInterface::ATOM),
        ];
    }
}
