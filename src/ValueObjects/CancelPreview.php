<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * What a cancel would do, before it is confirmed: the `mode` (`period_end` or `immediate`), when
 * the subscription ends, and the {@see BundleConsequence}s on the organization's other
 * subscriptions. Show every consequence's `message` before the cancel button.
 */
readonly class CancelPreview
{
    /** @param  list<BundleConsequence>  $consequences */
    public function __construct(
        public string $mode = 'period_end',
        public ?DateTimeImmutable $endsAt = null,
        public array $consequences = [],
        public ?string $subscription = null,
    ) {}

    /** True when cancelling costs another subscription something. */
    public function hasConsequences(): bool
    {
        return $this->consequences !== [];
    }
}
