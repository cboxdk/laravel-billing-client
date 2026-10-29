<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\SubscriptionTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\BillingPeriod;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\CheckoutSession;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PortalSession;
use Cbox\Billing\Client\ValueObjects\PreviewLine;
use Cbox\Billing\Client\ValueObjects\SetupIntent;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionChange;
use Cbox\Billing\Client\ValueObjects\SubscriptionDiscount;
use Cbox\Billing\Client\ValueObjects\SubscriptionPage;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use Throwable;

/**
 * The real {@see ManagementTransport}: it speaks the Cbox Billing management HTTP API
 * with a bearer token over Laravel's HTTP client. Like the enforcement transport it is
 * deny-by-default about responses — any non-2xx status, connection error, or malformed
 * body raises a {@see TransportException} rather than fabricating a success: a
 * {@see BillingRefused} when billing said no (a 4xx other than 408/429), a
 * {@see BillingUnavailable} when it could not answer. The one exception is the 404
 * {@see Subscription()} gets for an organization with no subscription, which is null. Every
 * value pulled off the wire is validated and cast explicitly; a missing or wrong-typed
 * required field is a malformed response, not a silent default.
 */
class HttpManagementTransport implements ManagementTransport, SubscriptionTransport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 5,
    ) {}

    public function plans(?string $currency = null): array
    {
        $query = $currency !== null && $currency !== '' ? '?currency='.rawurlencode(strtoupper($currency)) : '';
        $body = $this->get('/api/v1/plans'.$query);

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

    /**
     * The server answers 404 for an organization with no serving subscription
     * (`SubscriptionController::show`). That is the normal state of a brand-new
     * organization, so it reads as null. Throwing on it meant a new organization could not
     * even load the page it subscribes from. Every other refusal (401, 403) still throws,
     * and so does a 404 without the controller's `{error}` body: that is a route 404 from a
     * wrong base URL or an older server, not an answer.
     */
    public function subscription(string $org): ?Subscription
    {
        try {
            $body = $this->get('/api/v1/subscriptions/'.rawurlencode($org));
        } catch (BillingRefused $e) {
            if ($e->status === 404 && $this->isControllerNotFound($e->body)) {
                return null;
            }

            throw $e;
        }

        $raw = $body['subscription'] ?? $body;

        if (! is_array($raw) || ! is_string($raw['plan'] ?? null)) {
            return null;
        }

        return $this->toSubscription($raw, '/api/v1/subscriptions/{org}');
    }

    public function ensureOrganization(string $org, array $attributes): void
    {
        $this->putDiscardingBody('/api/v1/organizations/'.rawurlencode($org), $attributes);
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

        return $this->toChangePreview($this->post($path, ['plan' => $plan]), $path);
    }

    /**
     * Map the server's change-preview envelope.
     *
     * ONE mapper for both `/preview` and `/change`, because the server returns the identical
     * envelope from both — `presentPreview()` builds it in a single place. Two hand-written copies
     * are how `/change` came to read a `subscription` key the server never sends.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function toChangePreview(array $body, string $path): ChangePreview
    {
        $rawLines = $body['lines'] ?? [];
        $lines = [];

        if (is_array($rawLines)) {
            foreach ($rawLines as $line) {
                if (is_array($line)) {
                    // The server emits `minor` on a proration line, not `amount_minor`. Reading the
                    // wrong key threw whenever a change actually prorated — i.e. every mid-cycle
                    // change, which is the case these methods exist for.
                    $lines[] = new PreviewLine(
                        $this->stringOr($line, 'description', ''),
                        $this->intField($line, 'minor', $path),
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
            $this->toDiscount($body['discount'] ?? null, $path),
        );
    }

    /**
     * `POST /subscriptions/{org}/change` answers with a PREVIEW envelope — `{scheduled, due_now,
     * credit, new_recurring, effective_at, lines}` — not a subscription object. This method
     * required `$body['subscription']` and therefore threw on EVERY call, against every version of
     * the server. It now returns the change preview the endpoint actually produces.
     */
    public function changePlan(string $org, string $plan): ChangePreview
    {
        $path = '/api/v1/subscriptions/'.rawurlencode($org).'/change';

        return $this->toChangePreview($this->post($path, ['plan' => $plan]), $path);
    }

    /**
     * `POST /subscriptions/{org}/cancel` answers with the subscription UNWRAPPED. Requiring a
     * `subscription` envelope made this throw on every call.
     */
    public function cancel(string $org, bool $atPeriodEnd): Subscription
    {
        $path = '/api/v1/subscriptions/'.rawurlencode($org).'/cancel';

        return $this->toSubscription($this->post($path, ['at_period_end' => $atPeriodEnd]), $path);
    }

    /**
     * `GET /api/v1/subscriptions?updated_since=&cursor=&limit=` answers the cursor envelope every
     * list endpoint uses: `{data, has_more, next_cursor}`.
     */
    public function subscriptions(?DateTimeInterface $updatedSince = null, ?string $cursor = null, ?int $limit = null): SubscriptionPage
    {
        $path = '/api/v1/subscriptions';
        $query = array_filter([
            'updated_since' => $updatedSince?->format(DateTimeInterface::ATOM),
            'cursor' => $cursor,
            'limit' => $limit,
        ], static fn (string|int|null $value): bool => $value !== null && $value !== '');

        $body = $this->get($query === [] ? $path : $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $rawRows = $body['data'] ?? null;

        if (! is_array($rawRows)) {
            throw TransportException::malformed($path, 'missing data array');
        }

        $rows = [];

        foreach ($rawRows as $raw) {
            if (is_array($raw)) {
                $rows[] = $this->toSubscriptionChange($raw, $path);
            }
        }

        $next = $body['next_cursor'] ?? null;

        return new SubscriptionPage(
            $rows,
            ($body['has_more'] ?? false) === true,
            is_string($next) && $next !== '' ? $next : null,
        );
    }

    /**
     * `POST /subscriptions/{org}/reactivate` answers with the subscription unwrapped, like
     * `/cancel`. No body is sent.
     */
    public function reactivate(string $org): Subscription
    {
        $path = '/api/v1/subscriptions/'.rawurlencode($org).'/reactivate';

        return $this->toSubscription($this->post($path, []), $path);
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
                    // `allowance` is legitimately null for an unlimited meter — required-int
                    // parsing rejected it and took the whole usage view down with it.
                    $meters[$meter] = new MeterUsage(
                        $this->intField($definition, 'used', $path),
                        ($definition['allowance'] ?? null) === null ? null : $this->intField($definition, 'allowance', $path),
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

    public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null): CheckoutSession
    {
        $path = '/api/v1/checkout-sessions';

        /** @var array<string, mixed> $payload */
        $payload = ['org' => $org, 'plan' => $plan, 'return_url' => $returnUrl];

        if ($currency !== null) {
            $payload['currency'] = $currency;
        }

        $body = $this->post($path, $payload);
        $raw = is_array($body['checkout_session'] ?? null) ? $body['checkout_session'] : $body;

        return new CheckoutSession(
            url: $this->stringField($raw, 'url', $path),
            expiresAt: $this->date($raw['expires_at'] ?? null),
        );
    }

    public function createPortalSession(string $org, string $returnUrl): PortalSession
    {
        $path = '/api/v1/portal-sessions';
        $body = $this->post($path, ['org' => $org, 'return_url' => $returnUrl]);
        $raw = is_array($body['portal_session'] ?? null) ? $body['portal_session'] : $body;

        return new PortalSession(url: $this->stringField($raw, 'url', $path));
    }

    public function createSetupIntent(string $org): SetupIntent
    {
        $path = '/api/v1/setup-intents';
        $body = $this->post($path, ['org' => $org]);
        $raw = is_array($body['setup_intent'] ?? null) ? $body['setup_intent'] : $body;

        return new SetupIntent(
            gateway: $this->stringField($raw, 'gateway', $path),
            publishableKey: $this->stringField($raw, 'publishable_key', $path),
            clientSecret: $this->stringField($raw, 'client_secret', $path),
            status: $this->stringOr($raw, 'status', ''),
            reference: $this->stringOr($raw, 'reference', ''),
        );
    }

    public function createPaymentIntent(string $org, ?string $reference = null, ?int $amountMinor = null, ?string $currency = null): PaymentIntent
    {
        if ($reference === null && $amountMinor === null) {
            throw new InvalidArgumentException('createPaymentIntent requires either a reference or an amount.');
        }

        $path = '/api/v1/payment-intents';

        /** @var array<string, mixed> $payload */
        $payload = ['org' => $org];

        // The server validates `invoice` and `amount`, not `reference` and `amount_minor` —
        // posting the old names failed validation with a 422 on every call.
        if ($reference !== null) {
            $payload['invoice'] = $reference;
        }

        if ($amountMinor !== null) {
            $payload['amount'] = $amountMinor;
        }

        if ($currency !== null) {
            $payload['currency'] = $currency;
        }

        $body = $this->post($path, $payload);
        $raw = is_array($body['payment_intent'] ?? null) ? $body['payment_intent'] : $body;

        return new PaymentIntent(
            gateway: $this->stringField($raw, 'gateway', $path),
            publishableKey: $this->stringField($raw, 'publishable_key', $path),
            clientSecret: $this->stringField($raw, 'client_secret', $path),
            status: $this->stringOr($raw, 'status', ''),
            reference: $this->stringOr($raw, 'reference', ''),
        );
    }

    public function paymentMethods(string $org): array
    {
        $path = '/api/v1/payment-methods/'.rawurlencode($org);
        $body = $this->get($path);

        $rawMethods = $body['payment_methods'] ?? $body['data'] ?? null;

        if (! is_array($rawMethods)) {
            throw TransportException::malformed($path, 'missing payment methods array');
        }

        $methods = [];

        foreach ($rawMethods as $raw) {
            if (is_array($raw)) {
                $methods[] = new PaymentMethod(
                    id: $this->stringField($raw, 'id', $path),
                    brand: $this->stringOr($raw, 'brand', ''),
                    last4: $this->stringOr($raw, 'last4', ''),
                    expMonth: $this->intOr($raw, 'exp_month', 0),
                    expYear: $this->intOr($raw, 'exp_year', 0),
                    isDefault: (bool) ($raw['is_default'] ?? false),
                );
            }
        }

        return $methods;
    }

    public function setDefaultPaymentMethod(string $org, string $id): void
    {
        $this->postDiscardingBody(
            '/api/v1/payment-methods/'.rawurlencode($org).'/default',
            // The server validates `id`; `payment_method` was rejected with a 422 every time.
            ['id' => $id],
        );
    }

    public function removePaymentMethod(string $org, string $id): void
    {
        $this->deleteDiscardingBody(
            '/api/v1/payment-methods/'.rawurlencode($org).'/'.rawurlencode($id),
        );
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
                    allowance: ($definition['allowance'] ?? null) === null ? null : $this->toInt($definition['allowance']),
                    weight: $this->toFloat($definition['weight'] ?? 1.0),
                    overage: is_string($definition['overage'] ?? null) ? (string) $definition['overage'] : 'block',
                );
            }
        }

        // The catalog endpoint nests the resolved price (`price: {minor, currency}`);
        // accept the flat `price_minor`/`currency` shape too for older services.
        $price = is_array($raw['price'] ?? null) ? $raw['price'] : [];

        return new Plan(
            key: $this->stringOr($raw, 'key', ''),
            name: $this->stringOr($raw, 'name', ''),
            priceMinor: isset($price['minor']) ? $this->toInt($price['minor']) : $this->intOr($raw, 'price_minor', 0),
            currency: isset($price['currency']) && is_string($price['currency']) ? $price['currency'] : $this->stringOr($raw, 'currency', ''),
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
            discount: $this->toDiscount($raw['discount'] ?? null, $path),
            organizationRef: $this->nullableString($raw, 'organization_ref'),
            pauseReason: $this->nullableString($raw, 'pause_reason'),
            paused: ($raw['paused'] ?? false) === true,
        );
    }

    /**
     * One change-feed row. `id`, `organization_id` and `status` are required; the rest is
     * optional, so a server that predates a field still parses.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private function toSubscriptionChange(array $raw, string $path): SubscriptionChange
    {
        return new SubscriptionChange(
            id: $this->intField($raw, 'id', $path),
            organizationId: $this->stringField($raw, 'organization_id', $path),
            organizationRef: $this->nullableString($raw, 'organization_ref'),
            plan: $this->nullableString($raw, 'plan'),
            product: $this->nullableString($raw, 'product'),
            status: $this->stringField($raw, 'status', $path),
            paused: ($raw['paused'] ?? false) === true,
            pauseReason: $this->nullableString($raw, 'pause_reason'),
            seats: $this->intOr($raw, 'seats', 1),
            periodStart: $this->date($raw['period_start'] ?? null),
            periodEnd: $this->date($raw['period_end'] ?? null),
            cancelAtPeriodEnd: ($raw['cancel_at_period_end'] ?? false) === true,
            trialEndsAt: $this->date($raw['trial_ends_at'] ?? null),
            canceledAt: $this->date($raw['canceled_at'] ?? null),
            discount: $this->toDiscount($raw['discount'] ?? null, $path),
            updatedAt: $this->date($raw['updated_at'] ?? null),
        );
    }

    /**
     * The bound coupon, or null when there is none (or the server predates the field). A
     * discount object without its `code` or `duration` is malformed, not absent.
     */
    private function toDiscount(mixed $raw, string $path): ?SubscriptionDiscount
    {
        if (! is_array($raw)) {
            return null;
        }

        return new SubscriptionDiscount(
            code: $this->stringField($raw, 'code', $path),
            duration: $this->stringField($raw, 'duration', $path),
            name: $this->nullableString($raw, 'name'),
            percentOff: ($raw['percent_off'] ?? null) === null ? null : $this->intField($raw, 'percent_off', $path),
            amountOffMinor: ($raw['amount_off_minor'] ?? null) === null ? null : $this->intField($raw, 'amount_off_minor', $path),
            currency: $this->nullableString($raw, 'currency'),
            endsAt: $this->date($raw['ends_at'] ?? null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function toPaymentIntent(array $raw): PaymentIntent
    {
        return new PaymentIntent(
            gateway: $this->stringOr($raw, 'gateway', ''),
            publishableKey: $this->stringOr($raw, 'publishable_key', ''),
            clientSecret: is_string($raw['client_secret'] ?? null) ? (string) $raw['client_secret'] : null,
            status: $this->stringOr($raw, 'status', ''),
            reference: $this->stringOr($raw, 'reference', $this->stringOr($raw, 'id', '')),
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
            throw TransportException::fromResponse($path, $response);
        }

        return $this->decode($response->json(), $path);
    }

    /**
     * A POST whose success is all that matters (no response body is read) — for
     * side-effecting endpoints like setting a default payment method.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postDiscardingBody(string $path, array $payload): void
    {
        try {
            $response = $this->request()->post($this->url($path), $payload);
        } catch (ConnectionException $e) {
            throw TransportException::unreachable($path, $e);
        }

        if (! $response->successful()) {
            throw TransportException::fromResponse($path, $response);
        }
    }

    /**
     * A PUT whose success is all that matters (no response body is read) — for
     * idempotent upserts like organization provisioning.
     *
     * @param  array<string, mixed>  $payload
     */
    private function putDiscardingBody(string $path, array $payload): void
    {
        try {
            $response = $this->request()->put($this->url($path), $payload);
        } catch (ConnectionException $e) {
            throw TransportException::unreachable($path, $e);
        }

        if (! $response->successful()) {
            throw TransportException::fromResponse($path, $response);
        }
    }

    /**
     * A DELETE whose success is all that matters (no response body is read).
     */
    private function deleteDiscardingBody(string $path): void
    {
        try {
            $response = $this->request()->delete($this->url($path));
        } catch (ConnectionException $e) {
            throw TransportException::unreachable($path, $e);
        }

        if (! $response->successful()) {
            throw TransportException::fromResponse($path, $response);
        }
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

    /** True when a 404 body is the controller's `{"error": "..."}`, not Laravel's route 404. */
    private function isControllerNotFound(?string $body): bool
    {
        $decoded = json_decode((string) $body, true);

        return is_array($decoded) && is_string($decoded['error'] ?? null);
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
    private function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
