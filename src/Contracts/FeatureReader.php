<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\OrganizationFeatures;

interface FeatureReader
{
    /** Resolved plan grants and organization overrides from Billing. */
    public function features(string $organization): OrganizationFeatures;
}
