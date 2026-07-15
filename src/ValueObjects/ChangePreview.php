<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/**
 * A dry-run of a plan change (`POST /api/v1/subscriptions/{org}/preview`) so a product
 * app can show the user exactly what switching plans costs before committing: the net
 * `dueNowMinor` charge, any prorated `creditMinor` applied, the `newRecurringMinor`
 * amount going forward, when the change takes `effectiveAt`, and the itemized `lines`.
 * All amounts are integer minor units.
 */
readonly class ChangePreview
{
    /**
     * @param  list<PreviewLine>  $lines
     */
    public function __construct(
        public int $dueNowMinor,
        public int $creditMinor,
        public int $newRecurringMinor,
        public ?DateTimeImmutable $effectiveAt,
        public array $lines,
    ) {}
}
