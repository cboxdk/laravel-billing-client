<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/** A plan change scheduled for later (the period end): the `plan` key it moves to, and when. */
readonly class PendingChange
{
    public function __construct(
        public string $plan,
        public ?DateTimeImmutable $effectiveAt = null,
    ) {}
}
