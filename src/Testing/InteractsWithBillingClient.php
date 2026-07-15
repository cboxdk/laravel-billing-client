<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Buffers\ArrayUsageBuffer;
use Cbox\Billing\Client\Contracts\BillingSignals;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Leasing\ReservationSweeper;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\Signals\NullBillingSignals;
use Cbox\Billing\Client\Stores\ArrayReservationRegistry;
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
 * `$this->transport()->takeDown()` to simulate an unreachable billing service, flip
 * `$failurePolicy` to exercise the fail-open / fail-closed paths, set
 * `$this->billingNowMs` to drive the reservation-TTL clock, and use `$this->sweeper()`
 * to reclaim abandoned holds. `$this->makeBillingManagement()` builds the self-service
 * client over {@see FakeManagementTransport}.
 */
trait InteractsWithBillingClient
{
    /** Test-controlled millisecond clock for reservation TTLs; 0 uses the real clock. */
    public int $billingNowMs = 0;

    private ?FakeBillingTransport $billingTransport = null;

    private ?FakeManagementTransport $billingManagementTransport = null;

    private ?ArrayUsageBuffer $billingBuffer = null;

    private ?ArrayReservationRegistry $billingRegistry = null;

    private ?LocalLeaseStore $billingStore = null;

    private ?BillingSignals $billingSignals = null;

    private int $reservationIdSeq = 0;

    private int $billingReservationTtl = 300;

    protected function transport(): FakeBillingTransport
    {
        return $this->billingTransport ??= new FakeBillingTransport;
    }

    protected function managementTransport(): FakeManagementTransport
    {
        return $this->billingManagementTransport ??= new FakeManagementTransport;
    }

    protected function usageBuffer(): ArrayUsageBuffer
    {
        return $this->billingBuffer ??= new ArrayUsageBuffer;
    }

    protected function reservationRegistry(): ArrayReservationRegistry
    {
        return $this->billingRegistry ??= new ArrayReservationRegistry;
    }

    protected function signals(): BillingSignals
    {
        return $this->billingSignals ??= new NullBillingSignals;
    }

    /** Route the client's signals to a recording double and return it for assertions. */
    protected function recordSignals(): RecordingBillingSignals
    {
        $recording = new RecordingBillingSignals;
        $this->billingSignals = $recording;

        return $recording;
    }

    protected function makeBillingClient(
        int $leaseSize = 100,
        int $refillThreshold = 20,
        ?LocalLeaseStore $store = null,
        FailurePolicy $failurePolicy = FailurePolicy::Allow,
        int $reservationTtl = 300,
    ): BillingClient {
        $locks = null;

        if ($store === null) {
            $arrayStore = new ArrayStore;
            $store = new CacheLeaseStore(new Repository($arrayStore));
            $locks = $arrayStore;
        }

        $this->billingStore = $store;
        $this->billingReservationTtl = $reservationTtl;

        $transport = $this->transport();
        $buffer = $this->usageBuffer();

        $leases = new LeaseManager($transport, $store, $leaseSize, $refillThreshold, $locks, signals: $this->signals());
        $reporter = new UsageReporter($transport, $buffer, $this->signals());

        return new BillingClient(
            store: $store,
            buffer: $buffer,
            leases: $leases,
            reporter: $reporter,
            transport: $transport,
            failurePolicy: $failurePolicy,
            ids: fn (): string => 'res-'.(++$this->reservationIdSeq),
            registry: $this->reservationRegistry(),
            signals: $this->signals(),
            reservationTtl: $reservationTtl,
            clock: fn (): int => $this->billingNowMs > 0 ? $this->billingNowMs : (int) round(microtime(true) * 1000),
        );
    }

    /** A sweeper over the same store and registry the last {@see makeBillingClient()} used. */
    protected function sweeper(): ReservationSweeper
    {
        $store = $this->billingStore ??= new CacheLeaseStore(new Repository(new ArrayStore));

        return new ReservationSweeper($store, $this->reservationRegistry(), $this->signals());
    }

    protected function makeBillingManagement(): BillingManagement
    {
        return new BillingManagement($this->managementTransport());
    }
}
