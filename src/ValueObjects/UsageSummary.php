<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * An organization's per-meter usage for the current period
 * (`GET /api/v1/usage/{org}`): a map of meter name to {@see MeterUsage} plus the
 * {@see BillingPeriod} the figures cover. Deny-by-default: an unknown meter has no
 * entry and {@see for()} returns null rather than a fabricated zero.
 */
readonly class UsageSummary
{
    /**
     * @param  array<string, MeterUsage>  $meters
     */
    public function __construct(
        public array $meters,
        public BillingPeriod $period,
    ) {}

    /** The usage for `$meter`, or `null` when the summary carries none. */
    public function for(string $meter): ?MeterUsage
    {
        return $this->meters[$meter] ?? null;
    }
}
