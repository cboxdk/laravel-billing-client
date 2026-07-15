<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Tests\Fixtures;

use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Testing\InteractsWithBillingClient;

/**
 * A static composition site for {@see InteractsWithBillingClient} so PHPStan analyses
 * the testing trait against real call sites (the Pest suite itself is not in the
 * analysis paths). It mirrors how a host app would drive the client in its own tests.
 */
class BillingClientHarness
{
    use InteractsWithBillingClient;

    public function leaseTakeAndReport(): int
    {
        $this->transport()->grant('org_a', 'api.calls', 1_000);

        $client = $this->makeBillingClient(leaseSize: 100, failurePolicy: FailurePolicy::Allow);

        $reservation = $client->reserve('org_a', 'api.calls', 5);
        $client->commit($reservation, 5);

        return $client->report('org_a');
    }
}
