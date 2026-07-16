<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Tests\Fixtures;

use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Testing\InteractsWithBillingClient;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;

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

        // The single-meter reserve narrows to Reservation via the conditional return type.
        $reservation = $client->reserve('org_a', 'api.calls', 5);
        $client->commit($reservation, 5);

        return $client->report('org_a');
    }

    public function reserveSetTakeAndSweep(): int
    {
        $this->transport()->grant('org_a', 'api.calls', 1_000)->grant('org_a', 'storage.gb', 500);
        $this->recordSignals();

        $client = $this->makeBillingClient(leaseSize: 100, reservationTtl: 60);

        // The array form narrows to ReservationSet via the conditional return type.
        $set = $client->reserve('org_a', ['api.calls' => 5, 'storage.gb' => 2]);
        $client->commit($set, ['api.calls' => 5, 'storage.gb' => 2]);

        return $this->sweeper()->sweep((int) round(microtime(true) * 1000));
    }

    public function browsePlans(): int
    {
        return count($this->makeBillingManagement()->plans());
    }

    public function defaultPaymentMethodBrand(): string
    {
        $this->managementTransport()->withPaymentMethod(
            'org_a',
            new PaymentMethod('pm_1', 'visa', '4242', 12, 2030, isDefault: true),
        );

        $management = $this->makeBillingManagement();
        $management->createSetupIntent('org_a');
        $management->setDefaultPaymentMethod('org_a', 'pm_1');

        return $management->paymentMethods('org_a')[0]->brand;
    }
}
