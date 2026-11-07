<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlements;

/** The boolean/config feature API is separate from metered allowances. */
interface FeatureTransport
{
    /** @throws TransportException */
    public function features(string $org): FeatureEntitlements;
}
