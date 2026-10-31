<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * The plan as one subscription holds it (`terms` on a subscription read): the {@see Plan} at the
 * version the subscriber is on, with its limits, features and price, which `plans()` (the latest
 * version, of plans still sold) does not show. `version` is null for a subscription from before
 * the plan's first change, and `latest` says whether it is on the plan's latest version.
 */
readonly class SubscriptionTerms
{
    public function __construct(
        public Plan $plan,
        public ?int $version = null,
        public bool $latest = true,
    ) {}
}
