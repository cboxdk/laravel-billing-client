<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Enums\ReserveOutcome;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\LeaseGrant;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;
use RuntimeException;

/**
 * In-memory {@see BillingTransport} for tests — stands in for the remote billing
 * service. Give an org a total allowance per meter with {@see grant()}; each lease
 * reserves pessimistically from it and can never over-grant (the hard-limit
 * invariant), exactly as the real service's central budget would. Reported usage is
 * kept as the HIGHEST cumulative seen per (org, meter) — the same idempotent,
 * keep-the-max ingest the real service uses — so a dropped report self-corrects on
 * the next flush.
 *
 * {@see takeDown()} makes every call throw a {@see TransportException}, so tests can
 * exercise the fail-open / fail-closed policy against an unreachable billing service.
 */
class FakeBillingTransport implements BillingTransport
{
    /** @var array<string, int> total allowance per org:meter */
    private array $allowance = [];

    /** @var array<string, int> currently leased-out per org:meter */
    private array $leased = [];

    /** @var array<string, int> highest cumulative usage reported per org:meter */
    private array $reported = [];

    /** @var array<string, Entitlement> registered entitlements per org:meter */
    private array $entitlements = [];

    /** @var list<array{reservation_id: string, org: string}> */
    private array $reservations = [];

    private bool $down = false;

    private int $leaseCalls = 0;

    private int $reportCalls = 0;

    private int $reservationSeq = 0;

    /** Give (org, meter) a total central allowance to lease from. */
    public function grant(string $org, string $meter, int $allowance): self
    {
        $this->allowance[$this->key($org, $meter)] = $allowance;

        return $this;
    }

    /** Register the entitlement returned by {@see Entitlements()} for (org, meter). */
    public function entitlement(string $org, Entitlement $entitlement): self
    {
        $this->entitlements[$this->key($org, $entitlement->meter)] = $entitlement;

        return $this;
    }

    /** Simulate an outage: every subsequent call throws until {@see bringUp()}. */
    public function takeDown(): self
    {
        $this->down = true;

        return $this;
    }

    public function bringUp(): self
    {
        $this->down = false;

        return $this;
    }

    public function lease(string $org, string $meter, int $size): LeaseGrant
    {
        $this->guard('/api/v1/leases');
        $this->leaseCalls++;

        $key = $this->key($org, $meter);
        $available = ($this->allowance[$key] ?? 0) - ($this->leased[$key] ?? 0);
        $granted = max(0, min($size, $available));

        $this->leased[$key] = ($this->leased[$key] ?? 0) + $granted;

        return new LeaseGrant('lease_'.$org.'_'.$meter.'_'.$this->leaseCalls, $org, $meter, $granted);
    }

    public function reportUsage(string $org, array $entries): void
    {
        $this->guard('/api/v1/usage');
        $this->reportCalls++;

        foreach ($entries as $entry) {
            $key = $this->key($entry->org, $entry->meter);
            // Keep the max: cumulative reporting is idempotent and self-correcting.
            $this->reported[$key] = max($this->reported[$key] ?? 0, $entry->cumulative);
        }
    }

    public function reserve(string $org, array $meters): RemoteReservation
    {
        $this->guard('/api/v1/reserve');

        foreach ($meters as $meter) {
            $entitlement = $this->entitlements[$this->key($org, $meter->meter)] ?? null;

            if ($entitlement === null || ! $entitlement->enabled) {
                return new RemoteReservation(ReserveOutcome::Denied, reason: 'not entitled');
            }
        }

        $id = 'res_'.(++$this->reservationSeq);
        $this->reservations[] = ['reservation_id' => $id, 'org' => $org];

        return new RemoteReservation(ReserveOutcome::Allowed, reservationId: $id);
    }

    public function commit(string $reservationId, array $actuals): void
    {
        $this->guard('/api/v1/commit');

        foreach ($this->reservations as $reservation) {
            if ($reservation['reservation_id'] === $reservationId) {
                return;
            }
        }

        throw new RuntimeException("Unknown reservation [{$reservationId}].");
    }

    public function entitlements(string $org): Entitlements
    {
        $this->guard('/api/v1/entitlements');

        $meters = [];

        foreach ($this->entitlements as $key => $entitlement) {
            if (str_starts_with($key, $org.':')) {
                $meters[$entitlement->meter] = $entitlement;
            }
        }

        return new Entitlements($org, $meters);
    }

    /** Units currently leased out (reserved centrally) for (org, meter). */
    public function leasedOut(string $org, string $meter): int
    {
        return $this->leased[$this->key($org, $meter)] ?? 0;
    }

    /** The highest cumulative usage billing has ingested for (org, meter). */
    public function reportedCumulative(string $org, string $meter): int
    {
        return $this->reported[$this->key($org, $meter)] ?? 0;
    }

    public function leaseCalls(): int
    {
        return $this->leaseCalls;
    }

    public function reportCalls(): int
    {
        return $this->reportCalls;
    }

    private function guard(string $endpoint): void
    {
        if ($this->down) {
            throw TransportException::unreachable($endpoint, new RuntimeException('billing is down (test outage)'));
        }
    }

    private function key(string $org, string $meter): string
    {
        return $org.':'.$meter;
    }
}
