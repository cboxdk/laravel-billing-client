<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\LeaseGrant;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/**
 * In-memory {@see BillingTransport} for tests — stands in for the remote billing service.
 * Since 0.4.0 it is an in-memory SERVER behind the real {@see HttpBillingTransport}: each
 * call goes over a faked HTTP client, the fake answers with the JSON and status the Cbox
 * Billing enforcement controllers emit, and the real transport parses it. Fake and real
 * share one parser, so they cannot drift.
 *
 * Give an org a total allowance per meter with {@see grant()}; each lease reserves
 * pessimistically from it and can never over-grant (the hard-limit invariant), exactly as
 * the real service's central budget would. Reported usage is kept as the HIGHEST cumulative
 * seen per (org, meter) — the same idempotent, keep-the-max ingest the real service uses —
 * so a dropped report self-corrects on the next flush.
 *
 * {@see takeDown()} makes every call fail to connect ({@see BillingUnavailable}), so tests
 * can exercise the fail-open / fail-closed policy against an unreachable service.
 * {@see refuseWith()} makes every call answer a status instead, for example a 401 for a
 * revoked token ({@see BillingRefused}), which fails closed under every policy.
 */
class FakeBillingTransport implements BillingTransport
{
    private const string BASE_URL = 'https://billing.fake';

    /** Seconds a lease lives, as the server's `billing.api.lease_ttl_seconds` default. */
    private const int LEASE_TTL = 300;

    /** @var array<string, int> total allowance per org:meter */
    private array $allowance = [];

    /** @var array<string, int> currently leased-out per org:meter */
    private array $leased = [];

    /** @var array<string, int> highest cumulative usage reported per org:meter */
    private array $reported = [];

    /** @var array<string, Entitlement> registered entitlements per org:meter */
    private array $entitlements = [];

    /** @var array<string, string> open remote reservations: id => org */
    private array $reservations = [];

    private bool $down = false;

    private ?int $refusal = null;

    private int $leaseCalls = 0;

    private int $reportCalls = 0;

    private int $reservationSeq = 0;

    private ?HttpBillingTransport $wire = null;

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

    /** Simulate an outage: every subsequent call fails to connect until {@see bringUp()}. */
    public function takeDown(): self
    {
        $this->down = true;

        return $this;
    }

    /** End a {@see takeDown()} or {@see refuseWith()}. */
    public function bringUp(): self
    {
        $this->down = false;
        $this->refusal = null;

        return $this;
    }

    /**
     * Answer every subsequent call with `$status` until {@see bringUp()}: 401 for a revoked
     * token, 403 for a token scoped to another org, 429 or 503 for load shedding.
     */
    public function refuseWith(int $status): self
    {
        $this->refusal = $status;

        return $this;
    }

    public function lease(string $org, string $meter, int $size): LeaseGrant
    {
        return $this->wire()->lease($org, $meter, $size);
    }

    public function reportUsage(string $org, array $entries): void
    {
        $this->wire()->reportUsage($org, $entries);
    }

    public function reserve(string $org, array $meters): RemoteReservation
    {
        return $this->wire()->reserve($org, $meters);
    }

    public function commit(string $reservationId, array $actuals): void
    {
        $this->wire()->commit($reservationId, $actuals);
    }

    public function entitlements(string $org): Entitlements
    {
        return $this->wire()->entitlements($org);
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

    /**
     * The server half: what the fake answers to `$method $path` with `$payload`. The client
     * methods above reach it over HTTP; the package's fixture test calls it directly to hold
     * its shapes to the recorded server responses.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function respond(string $method, string $path, array $payload = []): FakeResponse
    {
        if ($this->refusal !== null) {
            return FakeResponse::error($this->refusal, 'Refused by the test.');
        }

        $segments = array_map(
            rawurldecode(...),
            explode('/', trim((string) preg_replace('#^/?api/v1/?#', '', $path), '/')),
        );

        return match (true) {
            $method === 'POST' && $segments === ['leases'] => $this->leaseSlice($payload),
            $method === 'POST' && $segments === ['usage'] => $this->ingest($payload),
            $method === 'POST' && $segments === ['reserve'] => $this->reserveSet($payload),
            $method === 'POST' && $segments === ['commit'] => $this->commitSet($payload),
            $method === 'GET' && count($segments) === 2 && $segments[0] === 'entitlements' => $this->presentEntitlements($segments[1]),
            default => FakeResponse::json(['message' => 'Not Found'], 404),
        };
    }

    private function wire(): HttpBillingTransport
    {
        return $this->wire ??= new HttpBillingTransport($this->server(), self::BASE_URL, 'fake-billing-token');
    }

    private function server(): HttpFactory
    {
        return (new HttpFactory)->fake(function (Request $request) {
            if ($this->down) {
                throw new ConnectionException('cURL error 7: billing is down (test outage)');
            }

            return $this->respond(
                $request->method(),
                (string) parse_url($request->url(), PHP_URL_PATH),
                $request->data(),
            )->toPromise();
        });
    }

    /**
     * `LeaseController`: `{lease_id, granted, expires_at}` with an ISO-8601 expiry.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function leaseSlice(array $payload): FakeResponse
    {
        $org = $this->string($payload, 'org');
        $meter = $this->string($payload, 'meter');
        $size = $payload['size'] ?? null;

        if ($org === '' || $meter === '' || ! is_int($size) || $size < 1) {
            return FakeResponse::validation('size', 'The size field must be at least 1.');
        }

        $this->leaseCalls++;

        $key = $this->key($org, $meter);
        $available = ($this->allowance[$key] ?? 0) - ($this->leased[$key] ?? 0);
        $granted = max(0, min($size, $available));

        $this->leased[$key] = ($this->leased[$key] ?? 0) + $granted;

        return FakeResponse::json([
            'lease_id' => 'lease_'.$org.'_'.$meter.'_'.$this->leaseCalls,
            'granted' => $granted,
            'expires_at' => (new DateTimeImmutable)->modify('+'.self::LEASE_TTL.' seconds')->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * `UsageController`: `{ok: true, accepted}`.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function ingest(array $payload): FakeResponse
    {
        $org = $this->string($payload, 'org');
        $entries = $payload['entries'] ?? null;

        if ($org === '' || ! is_array($entries) || $entries === []) {
            return FakeResponse::validation('entries', 'The entries field is required.');
        }

        $this->reportCalls++;
        $accepted = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['meter'] ?? null) || ! is_int($entry['cumulative'] ?? null)) {
                return FakeResponse::validation('entries', 'Each entry needs a meter, a cumulative and a seq.');
            }

            $key = $this->key($org, $entry['meter']);
            // Keep the max: cumulative reporting is idempotent and self-correcting.
            $this->reported[$key] = max($this->reported[$key] ?? 0, $entry['cumulative']);
            $accepted++;
        }

        return FakeResponse::json(['ok' => true, 'accepted' => $accepted]);
    }

    /**
     * `ReserveController`: `{outcome: allowed, reservation_id}` or `{outcome: denied, reason}`
     * with the engine's denial reason (`unknown_meter`, `disabled_meter`).
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function reserveSet(array $payload): FakeResponse
    {
        $org = $this->string($payload, 'org');
        $meters = $payload['meters'] ?? null;

        if ($org === '' || ! is_array($meters) || $meters === []) {
            return FakeResponse::validation('meters', 'The meters field is required.');
        }

        foreach ($meters as $row) {
            $meter = is_array($row) && is_string($row['meter'] ?? null) ? $row['meter'] : '';
            $entitlement = $this->entitlements[$this->key($org, $meter)] ?? null;

            if ($entitlement === null || ! $entitlement->enabled) {
                return FakeResponse::json([
                    'outcome' => 'denied',
                    'reason' => $entitlement === null ? 'unknown_meter' : 'disabled_meter',
                ]);
            }
        }

        $id = 'res_'.(++$this->reservationSeq);
        $this->reservations[$id] = $org;

        return FakeResponse::json(['outcome' => 'allowed', 'reservation_id' => $id]);
    }

    /**
     * `CommitController`: `{ok: true}`, or a 404 for an unknown or expired reservation.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function commitSet(array $payload): FakeResponse
    {
        $id = $this->string($payload, 'reservation_id');

        if (! isset($this->reservations[$id])) {
            return FakeResponse::error(404, 'Unknown or expired reservation.');
        }

        unset($this->reservations[$id]);

        return FakeResponse::json(['ok' => true]);
    }

    /** `EntitlementController` + `EntitlementsView`: a disabled meter has a null weight. */
    private function presentEntitlements(string $org): FakeResponse
    {
        $meters = [];

        foreach ($this->entitlements as $key => $entitlement) {
            if (! str_starts_with($key, $org.':')) {
                continue;
            }

            $meters[$entitlement->meter] = $entitlement->enabled
                ? ['enabled' => true, 'allowance' => $entitlement->allowance, 'weight' => $entitlement->weight, 'overage' => $entitlement->overage]
                : ['enabled' => false, 'allowance' => null, 'weight' => null, 'overage' => 'block'];
        }

        ksort($meters);

        return FakeResponse::json(['meters' => $meters]);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function key(string $org, string $meter): string
    {
        return $org.':'.$meter;
    }
}
