<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\FeatureReader;
use Cbox\Billing\Client\Contracts\FeatureTransport;
use Cbox\Billing\Client\Enums\FeatureSource;
use Cbox\Billing\Client\Enums\FeatureType;
use Cbox\Billing\Client\Enums\ReserveOutcome;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlement;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlements;
use Cbox\Billing\Client\ValueObjects\LeaseGrant;
use Cbox\Billing\Client\ValueObjects\MeterActual;
use Cbox\Billing\Client\ValueObjects\MeterEstimate;
use Cbox\Billing\Client\ValueObjects\RemoteReservation;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * The real {@see BillingTransport}: it speaks the Cbox Billing HTTP API with a bearer
 * token over Laravel's HTTP client. It is deny-by-default about responses — any
 * non-2xx status, connection error, or malformed body raises a
 * {@see TransportException} rather than fabricating a success: a {@see BillingRefused}
 * when billing said no (a 4xx other than 408/429), a {@see BillingUnavailable} when it
 * could not answer. The caller's failure policy resolves only the second. Every value pulled off
 * the wire is validated and cast explicitly; a missing or wrong-typed field is a
 * malformed response, not a silent zero.
 */
class HttpBillingTransport implements BillingTransport, FeatureReader, FeatureTransport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 5,
        private readonly int $connectTimeout = 2,
        private readonly ?int $leaseTimeout = null,
    ) {}

    /**
     * A lease refill uses its own, shorter `lease_timeout`: the one call a request may still
     * wait on (when its local slice is empty), so it gives up quickly.
     */
    public function lease(string $org, string $meter, int $size): LeaseGrant
    {
        $body = $this->post('/leases', [
            'org' => $org,
            'meter' => $meter,
            'size' => $size,
        ], $this->leaseTimeout);

        return new LeaseGrant(
            leaseId: WireId::from($body['lease_id'] ?? null) ?? throw TransportException::malformed('/leases', 'missing id field [lease_id]'),
            org: $org,
            meter: $meter,
            granted: $this->intField($body, 'granted', '/leases'),
            expiresAt: $this->epochMs($body['expires_at'] ?? null),
        );
    }

    public function reportUsage(string $org, array $entries): void
    {
        $this->post('/usage', [
            'org' => $org,
            'entries' => array_map(
                static fn (CumulativeUsage $entry): array => $entry->toEntry(),
                $entries,
            ),
        ]);
    }

    public function reserve(string $org, array $meters): RemoteReservation
    {
        $body = $this->post('/reserve', [
            'org' => $org,
            'meters' => array_map(
                static fn (MeterEstimate $estimate): array => $estimate->toArray(),
                $meters,
            ),
        ]);

        return new RemoteReservation(
            outcome: ReserveOutcome::fromWire($body['outcome'] ?? null),
            reservationId: WireId::from($body['reservation_id'] ?? null),
            reason: $this->optionalStringField($body, 'reason'),
        );
    }

    public function commit(string $reservationId, array $actuals): void
    {
        $this->post('/commit', [
            'reservation_id' => $reservationId,
            'actuals' => array_map(
                static fn (MeterActual $actual): array => $actual->toArray(),
                $actuals,
            ),
        ]);
    }

    public function entitlements(string $org): Entitlements
    {
        $body = $this->get('/entitlements/'.rawurlencode($org));

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
                    // NULL is an unlimited meter, not an exhausted one. Casting it to 0 told every
                    // consumer that gates on the allowance that an unlimited meter had nothing left.
                    allowance: ($definition['allowance'] ?? null) === null ? null : $this->toInt($definition['allowance']),
                    weight: $this->toFloat($definition['weight'] ?? 1.0),
                    overage: is_string($definition['overage'] ?? null) ? (string) $definition['overage'] : 'block',
                );
            }
        }

        return new Entitlements($org, $meters);
    }

    public function features(string $organization): FeatureEntitlements
    {
        $org = $organization;
        $path = '/entitlements/'.rawurlencode($org).'/features';
        $body = $this->get($path);
        $raw = $body['features'] ?? null;
        $account = $body['account'] ?? null;
        if (! is_array($raw) || ! is_array($account) || ! is_bool($account['suspended'] ?? null)) {
            throw TransportException::malformed($path, 'expected features and account suspension state');
        }
        $features = [];
        foreach ($raw as $key => $row) {
            if (! is_string($key) || $key === '' || ! is_array($row) || ! is_bool($row['enabled'] ?? null)
                || ! array_key_exists('value', $row) || (array_key_exists('source', $row) && ! is_string($row['source']))
                || ! is_string($row['type'] ?? null)) {
                throw TransportException::malformed($path, 'invalid feature decision');
            }
            $type = FeatureType::tryFrom($row['type']);
            $source = FeatureSource::tryFrom($row['source'] ?? 'default');
            $value = $row['value'];
            if ($type === null || $source === null || (! is_int($value) && ! is_string($value) && $value !== null)
                || ($type === FeatureType::Boolean && $value !== null)
                || ($type === FeatureType::Config && $row['enabled'] && $value === null)) {
                throw TransportException::malformed($path, 'invalid feature value');
            }
            $features[$key] = new FeatureEntitlement($key, $type, $row['enabled'], $value, $source);
        }

        return new FeatureEntitlements($org, $features, $account['suspended']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, ?int $timeout = null): array
    {
        try {
            $response = $this->request($timeout)->post($this->url($path), $payload);
        } catch (ConnectionException $e) {
            throw TransportException::unreachable($path, $e);
        }

        if (! $response->successful()) {
            throw TransportException::fromResponse($path, $response);
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
            throw TransportException::fromResponse($path, $response);
        }

        return $this->decode($response->json(), $path);
    }

    private function request(?int $timeout = null): PendingRequest
    {
        $timeout = max(1, $timeout ?? $this->timeout);

        return $this->http
            ->asJson()
            ->acceptJson()
            ->withToken($this->token)
            ->withoutRedirecting()
            ->connectTimeout(max(1, min($this->connectTimeout, $timeout)))
            ->timeout($timeout);
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
    private function intField(array $body, string $key, string $path): int
    {
        $value = $body[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw TransportException::malformed($path, "missing integer field [{$key}]");
        }

        return (int) $value;
    }

    /**
     * A lease expiry as a millisecond epoch. The server sends ISO-8601 (`LeaseController`);
     * an integer millisecond epoch is accepted too. Anything else is no expiry.
     */
    private function epochMs(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            $at = new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }

        return (int) $at->format('Uv');
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
