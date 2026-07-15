<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Buffers\ArrayUsageBuffer;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\Stores\CacheLeaseStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * Wire up the app-local billing client in tests against an in-memory billing service:
 *
 *     $this->transport()->grant('org_a', 'api.calls', 1_000);
 *     $client = $this->makeBillingClient(leaseSize: 100);
 *     $res = $client->reserve('org_a', 'api.calls', 5);
 *     $client->commit($res, 5);
 *
 * Reservation ids are deterministic so buffered/reported usage asserts cleanly. Call
 * `$this->transport()->takeDown()` to simulate an unreachable billing service, and flip
 * `$failurePolicy` to exercise the fail-open / fail-closed paths.
 */
trait InteractsWithBillingClient
{
    private ?FakeBillingTransport $billingTransport = null;

    private ?ArrayUsageBuffer $billingBuffer = null;

    private int $reservationIdSeq = 0;

    protected function transport(): FakeBillingTransport
    {
        return $this->billingTransport ??= new FakeBillingTransport;
    }

    protected function usageBuffer(): ArrayUsageBuffer
    {
        return $this->billingBuffer ??= new ArrayUsageBuffer;
    }

    protected function makeBillingClient(
        int $leaseSize = 100,
        int $refillThreshold = 20,
        ?LocalLeaseStore $store = null,
        FailurePolicy $failurePolicy = FailurePolicy::Allow,
    ): BillingClient {
        $store ??= new CacheLeaseStore(new Repository(new ArrayStore));
        $transport = $this->transport();
        $buffer = $this->usageBuffer();

        $leases = new LeaseManager($transport, $store, $leaseSize, $refillThreshold);
        $reporter = new UsageReporter($transport, $buffer);

        return new BillingClient(
            store: $store,
            buffer: $buffer,
            leases: $leases,
            reporter: $reporter,
            transport: $transport,
            failurePolicy: $failurePolicy,
            ids: fn (): string => 'res-'.(++$this->reservationIdSeq),
        );
    }
}
