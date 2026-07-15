<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * The bounds of a usage or billing period — `start` inclusive, `end` exclusive — as
 * reported alongside a {@see UsageSummary}. Either bound may be null when the service
 * does not scope the figures to a closed window.
 */
readonly class BillingPeriod
{
    public function __construct(
        public ?DateTimeImmutable $start = null,
        public ?DateTimeImmutable $end = null,
    ) {}
}
