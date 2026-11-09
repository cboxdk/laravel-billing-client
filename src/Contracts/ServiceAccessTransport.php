<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpServiceAccessTransport;
use Cbox\Billing\Client\Testing\FakeServiceAccessTransport;
use Cbox\Billing\Client\ValueObjects\AccessDecision;

/**
 * Billing's service-admission check, asked live. {@see HttpServiceAccessTransport} speaks the
 * HTTP API; {@see FakeServiceAccessTransport} is the scriptable test double.
 */
interface ServiceAccessTransport
{
    /**
     * May `$subject` (a Cbox ID user id), in `$organization` when given, use `$service`?
     *
     * @throws BillingRefused when Billing refuses the question (a wrong token, an unknown service)
     * @throws BillingUnavailable when Billing cannot answer
     */
    public function check(string $service, string $subject, ?string $organization = null): AccessDecision;
}
