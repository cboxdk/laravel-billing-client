<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Facades;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;
use Cbox\Billing\Client\ValueObjects\Reservation;
use Cbox\Billing\Client\ValueObjects\ReservationSet;
use Illuminate\Support\Facades\Facade;

/**
 * Facade over the {@see BillingClient} hot path.
 *
 * @method static Reservation|ReservationSet reserve(string $org, string|array<string, int> $meter, ?int $estimate = null)
 * @method static void commit(Reservation|ReservationSet $reservation, int|array<string, int> $actual)
 * @method static void release(Reservation|ReservationSet $reservation)
 * @method static bool can(string $org, string $meter, int $n)
 * @method static int balance(string $org, string $meter)
 * @method static int report(?string $org = null)
 * @method static float cost(string $org, string $meter, int $units)
 * @method static RemoteReservation reserveRemote(string $org, list<\Cbox\Billing\Client\ValueObjects\MeterEstimate> $meters)
 * @method static void commitRemote(string $reservationId, list<\Cbox\Billing\Client\ValueObjects\MeterActual> $actuals)
 * @method static Entitlements entitlements(string $org)
 *
 * @see BillingClient
 */
class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingClient::class;
    }
}
