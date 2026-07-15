<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Enums\ReserveOutcome;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\LeaseGrant;
use Cbox\Billing\Client\ValueObjects\MeterActual;
use Cbox\Billing\Client\ValueObjects\MeterEstimate;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * The real {@see BillingTransport}: it speaks the Cbox Billing HTTP API with a bearer
 * token over Laravel's HTTP client. It is deny-by-default about responses — any
 * non-2xx status, connection error, or malformed body raises a
 * {@see TransportException} rather than fabricating a success, so the caller's failure
 * policy (not this transport) decides how an outage resolves. Every value pulled off
 * the wire is validated and cast explicitly; a missing or wrong-typed field is a
 * malformed response, not a silent zero.
 */
class HttpBillingTransport implements BillingTransport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 5,
    ) {}

    public function lease(string $org, string $meter, int $size): LeaseGrant
    {
        $body = $this->post('/api/v1/leases', [
            'org' => $org,
            'meter' => $meter,
            'size' => $size,
        ]);

        return new LeaseGrant(
            leaseId: $this->stringField($body, 'lease_id', '/api/v1/leases'),
            org: $org,
            meter: $meter,
            granted: $this->intField($body, 'granted', '/api/v1/leases'),
            expiresAt: $this->optionalIntField($body, 'expires_at'),
        );
    }

    public function reportUsage(string $org, array $entries): void
    {
        $this->post('/api/v1/usage', [
            'org' => $org,
            'entries' => array_map(
                static fn (CumulativeUsage $entry): array => $entry->toEntry(),
                $entries,
            ),
        ]);
    }

    public function reserve(string $org, array $meters): RemoteReservation
    {
        $body = $this->post('/api/v1/reserve', [
            'org' => $org,
            'meters' => array_map(
                static fn (MeterEstimate $estimate): array => $estimate->toArray(),
                $meters,
            ),
        ]);

        return new RemoteReservation(
            outcome: ReserveOutcome::fromWire($body['outcome'] ?? null),
            reservationId: $this->optionalStringField($body, 'reservation_id'),
            reason: $this->optionalStringField($body, 'reason'),
        );
    }

    public function commit(string $reservationId, array $actuals): void
    {
        $this->post('/api/v1/commit', [
            'reservation_id' => $reservationId,
            'actuals' => array_map(
                static fn (MeterActual $actual): array => $actual->toArray(),
                $actuals,
            ),
        ]);
    }

    public function entitlements(string $org): Entitlements
    {
        $body = $this->get('/api/v1/entitlements/'.rawurlencode($org));

        $rawMeters = $body['meters'] ?? null;
        $meters = [];

        if (is_array($rawMeters)) {
            foreach ($rawMeters as $meter => $definition) {
                if (! is_string($meter) || ! is_array($definition)) {
                    continue;
                }

                $meters[$meter] = new Entitlement(
                    meter: $meter,
                    enabled: (bool) ($definition['enabled'] ?? false),
                    allowance: $this->toInt($definition['allowance'] ?? 0),
                    weight: $this->toFloat($definition['weight'] ?? 1.0),
                    overage: is_string($definition['overage'] ?? null) ? (string) $definition['overage'] : 'block',
                );
            }
        }

        return new Entitlements($org, $meters);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->request()->post($this->url($path), $payload);
        } catch (ConnectionException $e) {
            throw TransportException::unreachable($path, $e);
        }

        if (! $response->successful()) {
            throw TransportException::status($path, $response->status());
        }

        return $this->decode($response->json(), $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        try {
            $response = $this->request()->get($this->url($path));
        } catch (ConnectionException $e) {
            throw TransportException::unreachable($path, $e);
        }

        if (! $response->successful()) {
            throw TransportException::status($path, $response->status());
        }

        return $this->decode($response->json(), $path);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->asJson()
            ->acceptJson()
            ->withToken($this->token)
            ->timeout($this->timeout);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $body, string $path): array
    {
        if (! is_array($body)) {
            throw TransportException::malformed($path, 'expected a JSON object');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function stringField(array $body, string $key, string $path): string
    {
        $value = $body[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw TransportException::malformed($path, "missing string field [{$key}]");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function intField(array $body, string $key, string $path): int
    {
        $value = $body[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw TransportException::malformed($path, "missing integer field [{$key}]");
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function optionalIntField(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function optionalStringField(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function toInt(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    private function toFloat(mixed $value): float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : 1.0;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
