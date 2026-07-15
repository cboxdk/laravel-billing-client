<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Leasing;

use Cbox\Billing\Client\Contracts\BillingSignals;
use Cbox\Billing\Client\Contracts\LocalLeaseStore;
use Cbox\Billing\Client\Contracts\ReservationRegistry;
use Cbox\Billing\Client\Signals\NullBillingSignals;

/**
 * Reclaims abandoned local reservations. A held reservation records its lease-backed
 * units and a TTL in the {@see ReservationRegistry}; if the request that held them
 * never commits or releases (a crash, a killed worker), the units would otherwise be
 * stranded in the local slice for the rest of the period. This sweeper returns those
 * units to the {@see LocalLeaseStore} once the TTL passes, so a crashed request costs
 * at most one TTL of stranded capacity rather than leaking it permanently.
 *
 * Reclaiming a hold only credits back the LOCAL slice — it never touches billing's
 * central lease, which the cumulative report reconciles independently. Run it on a
 * schedule (the `billing:sweep-reservations` command) at roughly the reservation TTL.
 */
class ReservationSweeper
{
    public function __construct(
        private readonly LocalLeaseStore $store,
        private readonly ReservationRegistry $registry,
        private readonly BillingSignals $signals = new NullBillingSignals,
    ) {}

    /**
     * Return every reservation expired at `$now` (millisecond epoch) to its local
     * slices and drop it from the registry. Returns the number of reservations
     * reclaimed.
     */
    public function sweep(int $now): int
    {
        $reclaimed = 0;

        foreach ($this->registry->expired($now) as $pending) {
            foreach ($pending->holds as $meter => $amount) {
                $this->store->giveBack($pending->org, $meter, $amount);
                $this->signals->refilled($pending->org, $meter, $amount);
            }

            $this->registry->close($pending->id);
            $reclaimed++;
        }

        return $reclaimed;
    }
}
