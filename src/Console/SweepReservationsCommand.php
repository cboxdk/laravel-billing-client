<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Console;

use Cbox\Billing\Client\Leasing\ReservationSweeper;
use Illuminate\Console\Command;

/**
 * Returns abandoned local reservations to their slices — the recovery half of local
 * TTL enforcement. A held reservation whose request crashed before commit/release
 * would otherwise strand its lease-backed units for the rest of the period; this
 * command reclaims every reservation past its TTL. Schedule it at roughly the
 * reservation TTL so leaked capacity is bounded by one sweep interval.
 */
class SweepReservationsCommand extends Command
{
    protected $signature = 'billing:sweep-reservations';

    protected $description = 'Return abandoned (expired, uncommitted) local reservations to their leased slices.';

    public function handle(ReservationSweeper $sweeper): int
    {
        $reclaimed = $sweeper->sweep((int) round(microtime(true) * 1000));

        $this->info("Reclaimed {$reclaimed} abandoned reservation(s).");

        return self::SUCCESS;
    }
}
