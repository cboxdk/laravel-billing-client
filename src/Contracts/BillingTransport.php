<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\Testing\FakeBillingTransport;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\LeaseGrant;
use Cbox\Billing\Client\ValueObjects\MeterActual;
use Cbox\Billing\Client\ValueObjects\MeterEstimate;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;

/**
 * The remote seam to the Cbox Billing service — the ONLY thing in the SDK that
 * touches the network. Every method maps one-to-one onto a billing HTTP endpoint;
 * {@see HttpBillingTransport} speaks real HTTP and
 * {@see FakeBillingTransport} stands in for tests.
 * Depend on this interface, never on a concrete transport, so the whole hot path is
 * testable offline and a host can swap the transport.
 *
 * A method that cannot complete — network error, non-2xx, or malformed body — throws
 * a {@see TransportException}. It never returns a fabricated success: the caller's
 * failure policy, not the transport, decides how an outage resolves.
 */
interface BillingTransport
{
    /**
     * Lease up to `size` units of `meter` for `org` (`POST /api/v1/leases`). Billing
     * grants however many the central budget can currently reserve (0 when the
     * allowance is exhausted); the granted units are reserved centrally until used or
     * expired.
     *
     * @throws TransportException on an infrastructure fault
     */
    public function lease(string $org, string $meter, int $size): LeaseGrant;

    /**
     * Report the current CUMULATIVE usage totals for `org` (`POST /api/v1/usage`).
     * Cumulative and idempotent: billing keeps the highest total per (node, meter),
     * so re-sending is safe and a dropped report self-corrects on the next flush.
     *
     * @param  list<CumulativeUsage>  $entries
     *
     * @throws TransportException on an infrastructure fault
     */
    public function reportUsage(string $org, array $entries): void;

    /**
     * Authoritatively reserve a set of meter estimates for `org` in one round-trip
     * (`POST /api/v1/reserve`) — the synchronous path, distinct from the local leased
     * hot path. Returns billing's three-way decision.
     *
     * @param  list<MeterEstimate>  $meters
     *
     * @throws TransportException on an infrastructure fault
     */
    public function reserve(string $org, array $meters): RemoteReservation;

    /**
     * Settle an authoritative remote reservation to its actual usage
     * (`POST /api/v1/commit`).
     *
     * @param  list<MeterActual>  $actuals
     *
     * @throws TransportException on an infrastructure fault
     */
    public function commit(string $reservationId, array $actuals): void;

    /**
     * Read `org`'s entitlement set (`GET /api/v1/entitlements/{org}`).
     *
     * @throws TransportException on an infrastructure fault
     */
    public function entitlements(string $org): Entitlements;
}
