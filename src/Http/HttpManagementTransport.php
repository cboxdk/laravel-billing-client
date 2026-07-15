<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\BillingPeriod;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PreviewLine;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * The real {@see ManagementTransport}: it speaks the Cbox Billing management HTTP API
 * with a bearer token over Laravel's HTTP client. Like the enforcement transport it is
 * deny-by-default about responses — any non-2xx status, connection error, or malformed
 * body raises a {@see TransportException} rather than fabricating a success. Every
 * value pulled off the wire is validated and cast explicitly; a missing or wrong-typed
 * required field is a malformed response, not a silent default.
 */
class HttpManagementTransport implements ManagementTransport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 5,
    ) {}

    public function plans(): array
    {
        $body = $this->get('/api/v1/plans');

        $rawPlans = $body['plans'] ?? $body['data'] ?? null;

        if (! is_array($rawPlans)) {
            throw TransportException::malformed('/api/v1/plans', 'missing plans array');
        }

        $plans = [];

        foreach ($rawPlans as $raw) {
            if (is_array($raw)) {
                $plans[] = $this->toPlan($raw);
            }
        }

        return $plans;
    }

    public function subscription(string $org): ?Subscription
    {
        $body = $this->get('/api/v1/subscriptions/'.rawurlencode($org));

        $raw = $body['subscription'] ?? $body;

        if (! is_array($raw) || ! is_string($raw['plan'] ?? null)) {
            return null;
        }

        return $this->toSubscription($raw, '/api/v1/subscriptions/{org}');
    }

    public function subscribe(string $org, string $plan): SubscriptionResult
    {
        $body = $this->post('/api/v1/subscriptions', ['org' => $org, 'plan' => $plan]);

        $rawSubscription = $body['subscription'] ?? null;

        if (! is_array($rawSubscription)) {
            throw TransportException::malformed('/api/v1/subscriptions', 'missing subscription object');
        }

        $rawIntent = $body['payment_intent'] ?? null;

        return new SubscriptionResult(
            $this->toSubscription($rawSubscription, '/api/v1/subscriptions'),
            is_array($rawIntent) ? $this->toPaymentIntent($rawIntent) : null,
        );
    }

    public function previewChange(string $org, string $plan): ChangePreview
    {
        $path = '/api/v1/subscriptions/'.rawurlencode($org).'/preview';
        $body = $this->post($path, ['plan' => $plan]);

        $rawLines = $body['lines'] ?? [];
        $lines = [];

        if (is_array($rawLines)) {
            foreach ($rawLines as $line) {
                if (is_array($line)) {
                    $lines[] = new PreviewLine(
                        $this->stringOr($line, 'description', ''),
                        $this->intField($line, 'amount_minor', $path),
                    );
                }
            }
        }

        return new ChangePreview(
            $this->intField($body, 'due_now_minor', $path),
            $this->intField($body, 'credit_minor', $path),
            $this->intField($body, 'new_recurring_minor', $path),
            $this->date($body['effective_at'] ?? null),
            $lines,
        );
    }

    public function changePlan(string $org, string $plan): Subscription
    {
        $path = '/api/v1/subscriptions/'.rawurlencode($org).'/change';
        $body = $this->post($path, ['plan' => $plan]);

        $raw = $body['subscription'] ?? null;

        if (! is_array($raw)) {
            throw TransportException::malformed($path, 'missing subscription object');
        }

        return $this->toSubscription($raw, $path);
    }

    public function cancel(string $org, bool $atPeriodEnd): Subscription
    {
        $path = '/api/v1/subscriptions/'.rawurlencode($org).'/cancel';
        $body = $this->post($path, ['at_period_end' => $atPeriodEnd]);

        $raw = $body['subscription'] ?? null;

        if (! is_array($raw)) {
            throw TransportException::malformed($path, 'missing subscription object');
        }

        return $this->toSubscription($raw, $path);
    }

    public function usage(string $org): UsageSummary
    {
        $path = '/api/v1/usage/'.rawurlencode($org);
        $body = $this->get($path);

        $rawMeters = $body['meters'] ?? null;
        $meters = [];

        if (is_array($rawMeters)) {
            foreach ($rawMeters as $meter => $definition) {
                if (is_string($meter) && is_array($definition)) {
                    $meters[$meter] = new MeterUsage(
                        $this->intField($definition, 'used', $path),
                        $this->intField($definition, 'allowance', $path),
                        $this->intOr($definition, 'overage', 0),
                    );
                }
            }
        }

        $rawPeriod = $body['period'] ?? null;
        $period = is_array($rawPeriod)
            ? new BillingPeriod($this->date($rawPeriod['start'] ?? null), $this->date($rawPeriod['end'] ?? null))
            : new BillingPeriod;

        return new UsageSummary($meters, $period);
    }

    public function invoices(string $org): array
    {
        $path = '/api/v1/invoices/'.rawurlencode($org);
        $body = $this->get($path);

        $rawInvoices = $body['invoices'] ?? $body['data'] ?? null;

        if (! is_array($rawInvoices)) {
            throw TransportException::malformed($path, 'missing invoices array');
        }

        $invoices = [];

        foreach ($rawInvoices as $raw) {
            if (is_array($raw)) {
                $invoices[] = new Invoice(
                    $this->stringField($raw, 'number', $path),
                    $this->date($raw['date'] ?? null),
                    $this->intField($raw, 'amount_minor', $path),
                    $this->stringOr($raw, 'currency', ''),
                    $this->stringOr($raw, 'status', 'open'),
                );
            }
        }

        return $invoices;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function toPlan(array $raw): Plan
    {
        $rawEntitlements = $raw['entitlements'] ?? null;
        $entitlements = [];

        if (is_array($rawEntitlements)) {
            foreach ($rawEntitlements as $key => $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                // Entitlements may arrive keyed by meter or as a list with a `meter` field.
                $meter = is_string($key) ? $key : ($definition['meter'] ?? null);

                if (! is_string($meter) || $meter === '') {
                    continue;
                }

                $entitlements[] = new Entitlement(
                    meter: $meter,
                    enabled: (bool) ($definition['enabled'] ?? true),
                    allowance: $this->toInt($definition['allowance'] ?? 0),
                    weight: $this->toFloat($definition['weight'] ?? 1.0),
                    overage: is_string($definition['overage'] ?? null) ? (string) $definition['overage'] : 'block',
                );
            }
        }

        return new Plan(
            key: $this->stringOr($raw, 'key', ''),
            name: $this->stringOr($raw, 'name', ''),
            priceMinor: $this->intOr($raw, 'price_minor', 0),
            currency: $this->stringOr($raw, 'currency', ''),
            interval: $this->stringOr($raw, 'interval', ''),
            entitlements: $entitlements,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function toSubscription(array $raw, string $path): Subscription
    {
        return new Subscription(
            plan: $this->stringField($raw, 'plan', $path),
            status: $this->stringOr($raw, 'status', 'active'),
            periodStart: $this->date($raw['period_start'] ?? null),
            periodEnd: $this->date($raw['period_end'] ?? null),
            renewsAt: $this->date($raw['renews_at'] ?? null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function toPaymentIntent(array $raw): PaymentIntent
    {
        return new PaymentIntent(
            id: $this->stringOr($raw, 'id', ''),
            status: $this->stringOr($raw, 'status', ''),
            clientSecret: is_string($raw['client_secret'] ?? null) ? (string) $raw['client_secret'] : null,
        );
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

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $body
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
     * @param  array<array-key, mixed>  $body
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
     * @param  array<array-key, mixed>  $body
     */
    private function stringOr(array $body, string $key, string $default): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function intOr(array $body, string $key, int $default): int
    {
        $value = $body[$key] ?? null;

        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
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
