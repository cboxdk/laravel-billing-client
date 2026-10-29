<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Exceptions;

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\SubscriptionTransport;

/**
 * The bound transport does not implement the call. Raised by the management client for a
 * method added after the transport was written, for example the 0.4.2 change feed on a custom
 * {@see ManagementTransport} that does not also implement
 * {@see SubscriptionTransport}. A programming error, not an
 * outage: nothing was sent.
 */
class UnsupportedOperation extends BillingClientException
{
    public static function missingContract(object $transport, string $contract, string $method): self
    {
        return new self(sprintf(
            '%s() needs a transport that implements %s; %s does not.',
            $method,
            $contract,
            $transport::class,
        ));
    }
}
