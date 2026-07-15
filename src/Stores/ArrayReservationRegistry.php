<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Stores;

use Cbox\Billing\Client\Contracts\ReservationRegistry;
use Cbox\Billing\Client\ValueObjects\PendingReservation;

/**
 * In-memory {@see ReservationRegistry} for a single process and for tests. A
 * production registry backs the pending holds with a durable store so a process
 * restart still replays them to the sweeper; the contract is identical.
 */
class ArrayReservationRegistry implements ReservationRegistry
{
    /** @var array<string, PendingReservation> */
    private array $pending = [];

    public function open(string $id, string $org, array $holds, int $expiresAt): void
    {
        if ($holds === []) {
            return;
        }

        $this->pending[$id] = new PendingReservation($id, $org, $holds, $expiresAt);
    }

    public function close(string $id): void
    {
        unset($this->pending[$id]);
    }

    public function expired(int $now): array
    {
        $out = [];

        foreach ($this->pending as $pending) {
            if ($pending->expiresAt <= $now) {
                $out[] = $pending;
            }
        }

        return $out;
    }
}
