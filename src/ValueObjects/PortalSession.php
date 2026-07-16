<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A billing-portal session from the management API (`POST /api/v1/portal-sessions`): the
 * `url` the product app redirects the user to so billing hosts the self-service portal
 * (payment methods, invoices, plan management) on its own pages. Short-lived — mint one
 * per visit rather than caching it.
 */
readonly class PortalSession
{
    public function __construct(
        public string $url,
    ) {}
}
