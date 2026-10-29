<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\SubscriptionTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\CheckoutSession;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PortalSession;
use Cbox\Billing\Client\ValueObjects\SetupIntent;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionDiscount;
use Cbox\Billing\Client\ValueObjects\SubscriptionPage;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/**
 * In-memory {@see ManagementTransport} for tests. Since 0.4.0 it is an in-memory SERVER
 * behind the real {@see HttpManagementTransport}: every call goes over a faked HTTP client,
 * the fake answers with the JSON and status the Cbox Billing controllers emit, and the real
 * transport parses it. Fake and real share one parser, so they cannot drift; the server
 * shapes are pinned by the fixtures in this package's `tests/Fixtures/server` and checked
 * against a live server by the contract suite.
 *
 * It reproduces the server's invariants so a host app can drive self-service billing flows
 * offline:
 *
 *  - Status codes as the server sends them: an unknown plan, or a change/cancel/preview with
 *    no subscription, is a 404 ({@see BillingRefused}); an organization with no subscription
 *    reads as null; a provisioning call without a `name` is a 422.
 *  - Subscribe answers `payment_intent: null`, as the server does today.
 *  - Cancel defaults to period end. An immediate cancel ends the subscription, so it no
 *    longer reads back (the server only serves a serving subscription).
 *  - Proration: a plan change credits the current plan's price and charges only the net
 *    difference now.
 *  - The change feed (0.4.2) lists every subscription the fake has seen, including ones
 *    canceled outright, ordered by when it last changed and paged by an opaque cursor. Each
 *    row's `organization_ref` is the `{org}` you called with, as on the server, and
 *    `organization_id` a stand-in Billing id. Reactivate resumes a pause, undoes a
 *    period-end cancel, or brings back a canceled subscription; on an active one it is a 409.
 *  - {@see takeDown()} makes every call fail with a connection error
 *    ({@see BillingUnavailable}); {@see refuseWith()} makes every call answer a status, for
 *    example a 401 for a revoked token.
 *  - Payments (ADR-0009): hosted sessions carry an expiry (flip it into the past with
 *    {@see expireSessions()}); setup/payment intents come back `requires_action`; stored
 *    payment methods list, re-default, and remove, and an unknown id is a 404.
 *
 * Seed the world with {@see withPlan()}, {@see withSubscription()}, {@see withUsage()},
 * {@see withInvoice()}, and {@see withPaymentMethod()} before driving the client. Call
 * {@see requireProvisionedOrganizations()} to also refuse a subscribe or checkout for an
 * organization that was never provisioned, as the server does.
 */
class FakeManagementTransport implements ManagementTransport, SubscriptionTransport
{
    private const string BASE_URL = 'https://billing.fake';

    /** @var array<string, Plan> */
    private array $plans = [];

    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    /**
     * Every subscription the change feed lists, serving or not, keyed by org.
     *
     * @var array<string, array{id: int, subscription: Subscription, canceledAt: ?DateTimeImmutable, updatedAt: DateTimeImmutable}>
     */
    private array $records = [];

    private int $subscriptionSeq = 0;

    /** @var array<string, array<string, MeterUsage>> */
    private array $usage = [];

    /** @var array<string, list<Invoice>> */
    private array $invoices = [];

    /** @var array<string, list<PaymentMethod>> */
    private array $paymentMethods = [];

    /** @var array<string, array<string, mixed>> */
    private array $organizations = [];

    private bool $down = false;

    private ?int $refusal = null;

    private bool $sessionsExpired = false;

    private bool $requireProvisioned = false;

    private int $intentSeq = 0;

    private int $sessionSeq = 0;

    private ?HttpManagementTransport $wire = null;

    public function withPlan(Plan $plan): self
    {
        $this->plans[$plan->key] = $plan;

        return $this;
    }

    /**
     * Seed `org`'s subscription. `$updatedAt` sets when the change feed reports it last
     * changed (now when null), to test an `updatedSince` sync.
     */
    public function withSubscription(string $org, Subscription $subscription, ?DateTimeInterface $updatedAt = null): self
    {
        $this->subscriptions[$org] = $subscription;
        $this->record($org, $subscription, null, $updatedAt);

        return $this;
    }

    public function withUsage(string $org, string $meter, MeterUsage $usage): self
    {
        $this->usage[$org][$meter] = $usage;

        return $this;
    }

    public function withInvoice(string $org, Invoice $invoice): self
    {
        $this->invoices[$org][] = $invoice;

        return $this;
    }

    public function withPaymentMethod(string $org, PaymentMethod $method): self
    {
        $this->paymentMethods[$org][] = $method;

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

    /** Refuse a subscribe or checkout for an organization never passed to {@see ensureOrganization()}. */
    public function requireProvisionedOrganizations(bool $require = true): self
    {
        $this->requireProvisioned = $require;

        return $this;
    }

    /**
     * Make every checkout session the fake mints come back already expired, so a test can
     * drive {@see CheckoutSession::isExpired()} without waiting on the clock.
     */
    public function expireSessions(): self
    {
        $this->sessionsExpired = true;

        return $this;
    }

    /**
     * The organizations provisioned through {@see ensureOrganization()}, keyed by org id
     * — for asserting a consumer provisioned before subscribing.
     *
     * @return array<string, array<string, mixed>>
     */
    public function organizations(): array
    {
        return $this->organizations;
    }

    public function plans(?string $currency = null): array
    {
        return $this->wire()->plans($currency);
    }

    public function ensureOrganization(string $org, array $attributes): void
    {
        $this->wire()->ensureOrganization($org, $attributes);
    }

    public function subscription(string $org): ?Subscription
    {
        return $this->wire()->subscription($org);
    }

    public function subscribe(string $org, string $plan): SubscriptionResult
    {
        return $this->wire()->subscribe($org, $plan);
    }

    public function previewChange(string $org, string $plan): ChangePreview
    {
        return $this->wire()->previewChange($org, $plan);
    }

    public function changePlan(string $org, string $plan): ChangePreview
    {
        return $this->wire()->changePlan($org, $plan);
    }

    public function cancel(string $org, bool $atPeriodEnd): Subscription
    {
        return $this->wire()->cancel($org, $atPeriodEnd);
    }

    public function subscriptions(?DateTimeInterface $updatedSince = null, ?string $cursor = null, ?int $limit = null): SubscriptionPage
    {
        return $this->wire()->subscriptions($updatedSince, $cursor, $limit);
    }

    public function reactivate(string $org): Subscription
    {
        return $this->wire()->reactivate($org);
    }

    public function usage(string $org): UsageSummary
    {
        return $this->wire()->usage($org);
    }

    public function invoices(string $org): array
    {
        return $this->wire()->invoices($org);
    }

    public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null): CheckoutSession
    {
        return $this->wire()->createCheckoutSession($org, $plan, $returnUrl, $currency);
    }

    public function createPortalSession(string $org, string $returnUrl): PortalSession
    {
        return $this->wire()->createPortalSession($org, $returnUrl);
    }

    public function createSetupIntent(string $org): SetupIntent
    {
        return $this->wire()->createSetupIntent($org);
    }

    public function createPaymentIntent(string $org, ?string $reference = null, ?int $amountMinor = null, ?string $currency = null): PaymentIntent
    {
        return $this->wire()->createPaymentIntent($org, $reference, $amountMinor, $currency);
    }

    public function paymentMethods(string $org): array
    {
        return $this->wire()->paymentMethods($org);
    }

    public function setDefaultPaymentMethod(string $org, string $id): void
    {
        $this->wire()->setDefaultPaymentMethod($org, $id);
    }

    public function removePaymentMethod(string $org, string $id): void
    {
        $this->wire()->removePaymentMethod($org, $id);
    }

    /**
     * The server half: what the fake answers to `$method $path` with `$payload`. The client
     * methods above reach it over HTTP; the package's fixture test calls it directly to hold
     * its shapes to the recorded server responses.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  array<array-key, mixed>  $query
     */
    public function respond(string $method, string $path, array $payload = [], array $query = []): FakeResponse
    {
        if ($this->refusal !== null) {
            return FakeResponse::error($this->refusal, 'Refused by the test.');
        }

        $segments = array_map(
            rawurldecode(...),
            explode('/', trim((string) preg_replace('#^/?api/v1/?#', '', $path), '/')),
        );
        $count = count($segments);
        $resource = $segments[0];
        $action = $segments[2] ?? null;

        return match (true) {
            $method === 'GET' && $segments === ['plans'] => $this->listPlans($query),
            $method === 'GET' && $segments === ['subscriptions'] => $this->listSubscriptionChanges($query),
            $method === 'PUT' && $count === 2 && $resource === 'organizations' => $this->upsertOrganization($segments[1], $payload),
            $method === 'GET' && $count === 2 && $resource === 'subscriptions' => $this->showSubscription($segments[1]),
            $method === 'POST' && $segments === ['subscriptions'] => $this->storeSubscription($payload),
            $method === 'POST' && $count === 3 && $resource === 'subscriptions' && $action === 'preview' => $this->preview($segments[1], $payload),
            $method === 'POST' && $count === 3 && $resource === 'subscriptions' && $action === 'change' => $this->change($segments[1], $payload),
            $method === 'POST' && $count === 3 && $resource === 'subscriptions' && $action === 'cancel' => $this->cancelSubscription($segments[1], $payload),
            $method === 'POST' && $count === 3 && $resource === 'subscriptions' && $action === 'reactivate' => $this->reactivateSubscription($segments[1]),
            $method === 'GET' && $count === 2 && $resource === 'usage' => $this->usageSummary($segments[1]),
            $method === 'GET' && $count === 2 && $resource === 'invoices' => $this->listInvoices($segments[1]),
            $method === 'POST' && $segments === ['checkout-sessions'] => $this->checkoutSession($payload),
            $method === 'POST' && $segments === ['portal-sessions'] => $this->portalSession($payload),
            $method === 'POST' && $segments === ['setup-intents'] => $this->setupIntent($payload),
            $method === 'POST' && $segments === ['payment-intents'] => $this->paymentIntent($payload),
            $method === 'GET' && $count === 2 && $resource === 'payment-methods' => $this->listPaymentMethods($segments[1]),
            $method === 'POST' && $count === 3 && $resource === 'payment-methods' && $action === 'default' => $this->defaultPaymentMethod($segments[1], $payload),
            $method === 'DELETE' && $count === 3 && $resource === 'payment-methods' => $this->destroyPaymentMethod($segments[1], (string) $action),
            default => FakeResponse::json(['message' => 'Not Found'], 404),
        };
    }

    private function wire(): HttpManagementTransport
    {
        return $this->wire ??= new HttpManagementTransport(
            $this->server(),
            self::BASE_URL,
            'fake-management-token',
        );
    }

    private function server(): HttpFactory
    {
        return (new HttpFactory)->fake(function (Request $request) {
            if ($this->down) {
                throw new ConnectionException('cURL error 7: management API is down (test outage)');
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $this->respond(
                $request->method(),
                (string) parse_url($request->url(), PHP_URL_PATH),
                $request->data(),
                $query,
            )->toPromise();
        });
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    private function listPlans(array $query): FakeResponse
    {
        $requested = is_string($query['currency'] ?? null) && $query['currency'] !== '' ? strtoupper($query['currency']) : null;
        $plans = array_values($this->plans);

        // Mirror the server: a plan not priced in the requested currency is omitted.
        if ($requested !== null) {
            $plans = array_values(array_filter(
                $plans,
                static fn (Plan $plan): bool => strcasecmp($plan->currency, $requested) === 0,
            ));
        }

        return FakeResponse::json([
            'currency' => $requested ?? ($plans[0]->currency ?? 'DKK'),
        ] + $this->page(array_map($this->presentPlan(...), $plans)));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function upsertOrganization(string $org, array $payload): FakeResponse
    {
        if ($this->string($payload, 'name') === '') {
            return FakeResponse::validation('name', 'The name field is required.');
        }

        $attributes = [];

        foreach ($payload as $key => $value) {
            $attributes[(string) $key] = $value;
        }

        $existing = $this->organizations[$org] ?? null;

        if ($existing !== null) {
            // The server only applies the currency on create (one-way lock).
            unset($attributes['billing_currency']);
            $this->organizations[$org] = array_merge($existing, $attributes);

            return FakeResponse::json(['organization' => $this->presentOrganization($org)]);
        }

        $this->organizations[$org] = $attributes;

        return FakeResponse::json(['organization' => $this->presentOrganization($org)], 201);
    }

    private function showSubscription(string $org): FakeResponse
    {
        $subscription = $this->subscriptions[$org] ?? null;

        if ($subscription === null) {
            return FakeResponse::error(404, 'This organization has no active subscription.');
        }

        return FakeResponse::json($this->presentSubscription($org, $subscription));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function storeSubscription(array $payload): FakeResponse
    {
        $org = $this->string($payload, 'org');
        $key = $this->string($payload, 'plan');

        if ($org === '' || $key === '') {
            return FakeResponse::validation($org === '' ? 'org' : 'plan', 'The field is required.');
        }

        if ($this->requireProvisioned && ! isset($this->organizations[$org])) {
            return FakeResponse::error(404, 'Unknown organization.');
        }

        if (! isset($this->plans[$key])) {
            return FakeResponse::error(404, 'Unknown plan.');
        }

        $now = new DateTimeImmutable;
        $end = $now->modify('+1 month');
        $subscription = new Subscription($key, 'active', $now, $end, $end);

        $this->subscriptions[$org] = $subscription;
        $this->record($org, $subscription, fresh: true);

        return FakeResponse::json([
            'subscription' => $this->presentSubscription($org, $subscription),
            'coupon' => null,
            // The server answers null here today; a subscribe never hands back an intent.
            'payment_intent' => null,
        ], 201);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function preview(string $org, array $payload): FakeResponse
    {
        if (! isset($this->subscriptions[$org])) {
            return FakeResponse::error(404, 'This organization has no active subscription.');
        }

        $target = $this->plans[$this->string($payload, 'plan')] ?? null;

        if ($target === null) {
            return FakeResponse::error(404, 'Unknown plan.');
        }

        return FakeResponse::json($this->presentPreview($org, $target));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function change(string $org, array $payload): FakeResponse
    {
        $current = $this->subscriptions[$org] ?? null;

        if ($current === null) {
            return FakeResponse::error(404, 'This organization has no active subscription.');
        }

        $target = $this->plans[$this->string($payload, 'plan')] ?? null;

        if ($target === null) {
            return FakeResponse::error(404, 'Unknown plan.');
        }

        // The preview is priced against the plan being LEFT, so build it before switching.
        $preview = $this->presentPreview($org, $target);

        $this->subscriptions[$org] = new Subscription(
            plan: $target->key,
            status: 'active',
            periodStart: $current->periodStart,
            periodEnd: $current->periodEnd,
            renewsAt: $current->periodEnd,
            discount: $current->discount,
            organizationRef: $current->organizationRef,
        );
        $this->record($org, $this->subscriptions[$org]);

        return FakeResponse::json(['scheduled' => false] + $preview);
    }

    /**
     * The server reads `mode` (immediate, period_end) or, without it, `at_period_end`, which
     * defaults to TRUE.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function cancelSubscription(string $org, array $payload): FakeResponse
    {
        $current = $this->subscriptions[$org] ?? null;

        if ($current === null) {
            return FakeResponse::error(404, 'This organization has no active subscription.');
        }

        $mode = $payload['mode'] ?? null;
        $atPeriodEnd = is_string($mode)
            ? $mode !== 'immediate'
            : filter_var($payload['at_period_end'] ?? true, FILTER_VALIDATE_BOOL);

        $now = new DateTimeImmutable;
        $updated = new Subscription(
            plan: $current->plan,
            status: $atPeriodEnd ? 'active' : 'canceled',
            periodStart: $current->periodStart,
            periodEnd: $atPeriodEnd ? $current->periodEnd : $now,
            renewsAt: null,
            discount: $current->discount,
            organizationRef: $current->organizationRef,
            pauseReason: $current->pauseReason,
            paused: $current->paused,
        );

        if ($atPeriodEnd) {
            $this->subscriptions[$org] = $updated;
        } else {
            // A canceled subscription is no longer serving, so the server stops returning it.
            unset($this->subscriptions[$org]);
        }

        $this->record($org, $updated, $atPeriodEnd ? null : $now);

        return FakeResponse::json($this->presentSubscription($org, $updated, $atPeriodEnd ? null : $now));
    }

    /**
     * `SubscriptionController::reactivate`: resume a pause, undo a period-end cancel, or bring
     * back a canceled subscription for a fresh period. 404 with no subscription at all, 409 for
     * one that is already active.
     */
    private function reactivateSubscription(string $org): FakeResponse
    {
        $record = $this->records[$org] ?? null;

        if ($record === null) {
            return FakeResponse::error(404, 'This organization has no subscription.');
        }

        $current = $record['subscription'];
        $serving = isset($this->subscriptions[$org]);

        if ($serving && ! $current->isPaused() && ! $current->cancelsAtPeriodEnd()) {
            return FakeResponse::error(409, 'This subscription cannot be reactivated.');
        }

        $now = new DateTimeImmutable;
        $start = $serving ? $current->periodStart : $now;
        $end = $serving ? $current->periodEnd : $now->modify('+1 month');

        $reactivated = new Subscription(
            plan: $current->plan,
            status: 'active',
            periodStart: $start,
            periodEnd: $end,
            renewsAt: $end,
            discount: $current->discount,
            organizationRef: $current->organizationRef,
        );

        $this->subscriptions[$org] = $reactivated;
        $this->record($org, $reactivated);

        return FakeResponse::json($this->presentSubscription($org, $reactivated));
    }

    /**
     * `SubscriptionChangesController`: rows ordered by `(updated_at, id)`, filtered by
     * `updated_since`, paged by an opaque cursor that is strictly after the last row.
     *
     * @param  array<array-key, mixed>  $query
     */
    private function listSubscriptionChanges(array $query): FakeResponse
    {
        $since = is_string($query['updated_since'] ?? null) && $query['updated_since'] !== ''
            ? new DateTimeImmutable($query['updated_since'])
            : null;

        $after = null;

        if (is_string($query['cursor'] ?? null) && $query['cursor'] !== '') {
            $decoded = json_decode((string) base64_decode(strtr($query['cursor'], '-_', '+/'), true), true);

            if (! is_array($decoded) || ! is_string($decoded[0] ?? null) || ! is_int($decoded[1] ?? null)) {
                return FakeResponse::error(400, 'The cursor is invalid.');
            }

            $after = [$decoded[0], $decoded[1]];
        }

        $limit = is_numeric($query['limit'] ?? null) ? max(1, min(100, (int) $query['limit'])) : 20;

        $rows = [];

        foreach ($this->records as $org => $record) {
            $key = [$record['updatedAt']->format('U.u'), $record['id']];

            if ($since !== null && $record['updatedAt'] < $since) {
                continue;
            }

            if ($after !== null && $key <= $after) {
                continue;
            }

            $rows[] = ['org' => $org, 'key' => $key] + $record;
        }

        usort($rows, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return FakeResponse::json([
            'data' => array_map(
                fn (array $row): array => $this->presentChange($row['org'], $row['id'], $row['subscription'], $row['canceledAt'], $row['updatedAt']),
                $rows,
            ),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $last !== null
                ? rtrim(strtr(base64_encode((string) json_encode($last['key'])), '+/', '-_'), '=')
                : null,
        ]);
    }

    /**
     * Note a change for the feed. A subscribe starts a new subscription (a new id); every other
     * change keeps the organization's id.
     */
    private function record(string $org, Subscription $subscription, ?DateTimeImmutable $canceledAt = null, ?DateTimeInterface $at = null, bool $fresh = false): void
    {
        $existing = $this->records[$org] ?? null;

        $this->records[$org] = [
            'id' => $existing === null || $fresh ? ++$this->subscriptionSeq : $existing['id'],
            'subscription' => $subscription,
            'canceledAt' => $canceledAt,
            'updatedAt' => $at === null ? new DateTimeImmutable : DateTimeImmutable::createFromInterface($at),
        ];
    }

    private function usageSummary(string $org): FakeResponse
    {
        $now = new DateTimeImmutable;
        $meters = [];

        foreach ($this->usage[$org] ?? [] as $meter => $usage) {
            $meters[$meter] = [
                'used' => $usage->used,
                'allowance' => $usage->allowance,
                'overage' => $usage->overage,
                'projected' => $usage->used,
                'projected_overage' => $usage->overage,
            ];
        }

        return FakeResponse::json([
            'period' => [
                'start' => $this->date($now->modify('-15 days')),
                'end' => $this->date($now->modify('+15 days')),
                'elapsed_fraction' => 0.5,
            ],
            'meters' => $meters,
        ]);
    }

    private function listInvoices(string $org): FakeResponse
    {
        return FakeResponse::json($this->page(array_map(
            fn (Invoice $invoice): array => [
                'number' => $invoice->number,
                'date' => $this->date($invoice->date),
                'amount_minor' => $invoice->amountMinor,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
            ],
            $this->invoices[$org] ?? [],
        )));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function checkoutSession(array $payload): FakeResponse
    {
        $org = $this->string($payload, 'org');

        if ($this->requireProvisioned && ! isset($this->organizations[$org])) {
            return FakeResponse::error(404, 'Unknown organization.');
        }

        if (! isset($this->plans[$this->string($payload, 'plan')])) {
            return FakeResponse::error(404, 'Unknown plan.');
        }

        $now = new DateTimeImmutable;

        return FakeResponse::json([
            'url' => 'https://billing.example/checkout/'.rawurlencode($org).'/'.(++$this->sessionSeq),
            'expires_at' => $this->date($this->sessionsExpired ? $now->modify('-1 minute') : $now->modify('+30 minutes')),
        ], 201);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function portalSession(array $payload): FakeResponse
    {
        return FakeResponse::json([
            'url' => 'https://billing.example/portal/'.rawurlencode($this->string($payload, 'org')).'/'.(++$this->sessionSeq),
            'expires_at' => $this->date((new DateTimeImmutable)->modify('+30 minutes')),
        ], 201);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function setupIntent(array $payload): FakeResponse
    {
        $org = $this->string($payload, 'org');
        $seq = ++$this->intentSeq;

        return FakeResponse::json([
            'gateway' => 'sandbox',
            'publishable_key' => 'pk_test_'.$org,
            'client_secret' => 'seti_secret_'.$org.'_'.$seq,
            'status' => 'requires_action',
            'reference' => 'seti_'.$seq,
        ], 201);
    }

    /**
     * The server validates `invoice` (a reference) or `amount` + `currency`.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function paymentIntent(array $payload): FakeResponse
    {
        $org = $this->string($payload, 'org');
        $invoice = $this->string($payload, 'invoice');

        if ($invoice === '' && ! is_int($payload['amount'] ?? null)) {
            return FakeResponse::validation('amount', 'The amount field is required when invoice is not present.');
        }

        if ($invoice === '' && $this->string($payload, 'currency') === '') {
            return FakeResponse::validation('currency', 'The currency field is required when amount is present.');
        }

        $seq = ++$this->intentSeq;

        return FakeResponse::json([
            'gateway' => 'sandbox',
            'publishable_key' => 'pk_test_'.$org,
            'client_secret' => 'pi_secret_'.$org.'_'.$seq,
            'status' => 'requires_action',
            'reference' => $invoice !== '' ? $invoice : 'pi_'.$seq,
        ], 201);
    }

    private function listPaymentMethods(string $org): FakeResponse
    {
        return FakeResponse::json($this->page(array_map(
            $this->presentPaymentMethod(...),
            $this->paymentMethods[$org] ?? [],
        )));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function defaultPaymentMethod(string $org, array $payload): FakeResponse
    {
        $id = $this->string($payload, 'id');

        if ($id === '') {
            return FakeResponse::validation('id', 'The id field is required.');
        }

        $found = false;
        $updated = [];

        foreach ($this->paymentMethods[$org] ?? [] as $method) {
            $found = $found || $method->id === $id;
            $updated[] = new PaymentMethod($method->id, $method->brand, $method->last4, $method->expMonth, $method->expYear, $method->id === $id);
        }

        if (! $found) {
            return FakeResponse::error(404, 'Unknown payment method.');
        }

        $this->paymentMethods[$org] = $updated;

        return FakeResponse::json(['data' => array_map($this->presentPaymentMethod(...), $updated)]);
    }

    private function destroyPaymentMethod(string $org, string $id): FakeResponse
    {
        $methods = $this->paymentMethods[$org] ?? [];
        $remaining = array_values(array_filter($methods, static fn (PaymentMethod $method): bool => $method->id !== $id));

        if (count($remaining) === count($methods)) {
            return FakeResponse::error(404, 'Unknown payment method.');
        }

        $this->paymentMethods[$org] = $remaining;

        return FakeResponse::noContent();
    }

    /**
     * `SubscriptionController::present()`.
     *
     * @return array<string, mixed>
     */
    private function presentSubscription(string $org, Subscription $subscription, ?DateTimeImmutable $canceledAt = null): array
    {
        return [
            'plan' => $subscription->plan,
            'organization_ref' => $subscription->organizationRef ?? $org,
            'status' => $subscription->status,
            'paused' => $subscription->isPaused(),
            'pause_reason' => $subscription->pauseReason,
            'discount' => $this->presentDiscount($subscription->discount),
            'seats' => 1,
            'period_start' => $this->date($subscription->periodStart),
            'period_end' => $this->date($subscription->periodEnd),
            'renews_at' => $this->date($subscription->renewsAt),
            'trial_ends_at' => null,
            'canceled_at' => $this->date($canceledAt),
            'pending_change' => null,
            'add_ons' => [],
        ];
    }

    /**
     * `SubscriptionChangesController::present()`.
     *
     * @return array<string, mixed>
     */
    private function presentChange(string $org, int $id, Subscription $subscription, ?DateTimeImmutable $canceledAt, DateTimeImmutable $updatedAt): array
    {
        return [
            'id' => $id,
            'organization_id' => $this->billingId($org),
            'organization_ref' => $subscription->organizationRef ?? $org,
            'plan' => $subscription->plan,
            'product' => null,
            'status' => $subscription->status,
            'paused' => $subscription->isPaused(),
            'pause_reason' => $subscription->pauseReason,
            'seats' => 1,
            'period_start' => $this->date($subscription->periodStart),
            'period_end' => $this->date($subscription->periodEnd),
            'cancel_at_period_end' => $canceledAt === null && $subscription->cancelsAtPeriodEnd(),
            'trial_ends_at' => null,
            'canceled_at' => $this->date($canceledAt),
            'discount' => $this->presentDiscount($subscription->discount),
            'updated_at' => $this->date($updatedAt),
        ];
    }

    /**
     * The bound-coupon block on every subscription payload and preview.
     *
     * @return array<string, mixed>|null
     */
    private function presentDiscount(?SubscriptionDiscount $discount): ?array
    {
        if ($discount === null) {
            return null;
        }

        return [
            'code' => $discount->code,
            'name' => $discount->name,
            'percent_off' => $discount->percentOff,
            'amount_off_minor' => $discount->amountOffMinor,
            'currency' => $discount->currency,
            'duration' => $discount->duration,
            'ends_at' => $this->date($discount->endsAt),
        ];
    }

    /** A stand-in for Billing's own organization id, stable per org. */
    private function billingId(string $org): string
    {
        return '01FAKE'.strtoupper(substr(hash('sha256', $org), 0, 20));
    }

    /**
     * `SubscriptionController::presentPreview()`.
     *
     * @return array<string, mixed>
     */
    private function presentPreview(string $org, Plan $target): array
    {
        $current = $this->subscriptions[$org] ?? null;
        $currentPlan = $current !== null ? ($this->plans[$current->plan] ?? null) : null;
        $credit = $currentPlan !== null ? $currentPlan->priceMinor : 0;

        $lines = [['description' => 'Subscribe to '.$target->name, 'minor' => $target->priceMinor]];

        if ($currentPlan !== null && $credit > 0) {
            $lines[] = ['description' => 'Credit for unused '.$currentPlan->name, 'minor' => -$credit];
        }

        return [
            'due_now_minor' => max(0, $target->priceMinor - $credit),
            'credit_minor' => $credit,
            'new_recurring_minor' => $target->priceMinor,
            'effective_at' => $this->date(new DateTimeImmutable),
            'credit_delta' => ['forfeited' => 0, 'granted' => 0, 'carried' => 0],
            'lines' => $lines,
            'discount' => $this->presentDiscount($current?->discount),
        ];
    }

    /**
     * `PlanCatalogView::presentOne()`.
     *
     * @return array<string, mixed>
     */
    private function presentPlan(Plan $plan): array
    {
        $entitlements = [];

        foreach ($plan->entitlements as $entitlement) {
            $entitlements[$entitlement->meter] = $this->presentEntitlement($entitlement);
        }

        ksort($entitlements);

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'interval' => $plan->interval,
            'entitlements' => $entitlements,
            'price' => ['minor' => $plan->priceMinor, 'currency' => $plan->currency],
        ];
    }

    /**
     * @return array{enabled: bool, allowance: int|null, weight: float|null, overage: string}
     */
    private function presentEntitlement(Entitlement $entitlement): array
    {
        if (! $entitlement->enabled) {
            return ['enabled' => false, 'allowance' => null, 'weight' => null, 'overage' => 'block'];
        }

        return [
            'enabled' => true,
            'allowance' => $entitlement->allowance,
            'weight' => $entitlement->weight,
            'overage' => $entitlement->overage,
        ];
    }

    /**
     * `OrganizationController::present()`.
     *
     * @return array<string, mixed>
     */
    private function presentOrganization(string $org): array
    {
        $attributes = $this->organizations[$org] ?? [];

        return [
            'id' => $org,
            'external_ref' => $attributes['external_ref'] ?? null,
            'name' => $attributes['name'] ?? null,
            'billing_email' => $attributes['billing_email'] ?? null,
            'billing_country' => $attributes['billing_country'] ?? null,
            'billing_currency' => $attributes['billing_currency'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPaymentMethod(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'brand' => $method->brand,
            'last4' => $method->last4,
            'exp_month' => $method->expMonth,
            'exp_year' => $method->expYear,
            'is_default' => $method->isDefault,
        ];
    }

    /**
     * `CursorPage::envelope()` — every list endpoint pages. The fake always returns one page.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function page(array $items): array
    {
        return ['data' => $items, 'has_more' => false, 'next_cursor' => null];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** ISO-8601, as the server's `toIso8601String()` writes it. */
    private function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
