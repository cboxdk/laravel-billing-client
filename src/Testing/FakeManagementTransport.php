<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\BillingPeriod;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\CheckoutSession;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\MeterUsage;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PortalSession;
use Cbox\Billing\Client\ValueObjects\PreviewLine;
use Cbox\Billing\Client\ValueObjects\SetupIntent;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * In-memory {@see ManagementTransport} for tests — stands in for the remote management
 * API and reproduces its invariants so a host app can drive self-service billing flows
 * offline:
 *
 *  - Deny-by-default: an unknown plan on subscribe/change/preview and an unknown org
 *    on change/cancel are refused with a {@see TransportException} (a 404), exactly as
 *    the real service's non-2xx would surface — never a fabricated success.
 *  - Proration: a plan change credits the current plan's price and charges only the
 *    net difference now, matching the service's preview math.
 *  - {@see takeDown()} makes every call throw, so tests can exercise deny-by-default on
 *    an unreachable management API.
 *  - Payments (ADR-0009): hosted checkout/portal sessions carry an expiry (flip it into
 *    the past with {@see expireSessions()} to drive {@see CheckoutSession::isExpired()});
 *    setup/payment intents come back with a `requires_action` status so the SCA branch is
 *    exercised; and stored payment methods list, re-default, and remove — a re-default or
 *    remove of an unknown id is refused with a 404, exactly as the service would.
 *
 * Seed the world with {@see withPlan()}, {@see withSubscription()}, {@see withUsage()},
 * {@see withInvoice()}, and {@see withPaymentMethod()} before driving the client.
 */
class FakeManagementTransport implements ManagementTransport
{
    /** @var array<string, Plan> */
    private array $plans = [];

    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    /** @var array<string, array<string, MeterUsage>> */
    private array $usage = [];

    /** @var array<string, list<Invoice>> */
    private array $invoices = [];

    /** @var array<string, list<PaymentMethod>> */
    private array $paymentMethods = [];

    private bool $down = false;

    private bool $sessionsExpired = false;

    private int $intentSeq = 0;

    private int $sessionSeq = 0;

    public function withPlan(Plan $plan): self
    {
        $this->plans[$plan->key] = $plan;

        return $this;
    }

    public function withSubscription(string $org, Subscription $subscription): self
    {
        $this->subscriptions[$org] = $subscription;

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

    /**
     * Make every checkout session the fake mints come back already expired, so a test can
     * drive {@see CheckoutSession::isExpired()} without waiting on the clock.
     */
    public function expireSessions(): self
    {
        $this->sessionsExpired = true;

        return $this;
    }

    public function plans(): array
    {
        $this->guard('/api/v1/plans');

        return array_values($this->plans);
    }

    public function subscription(string $org): ?Subscription
    {
        $this->guard('/api/v1/subscriptions/'.$org);

        return $this->subscriptions[$org] ?? null;
    }

    public function subscribe(string $org, string $plan): SubscriptionResult
    {
        $this->guard('/api/v1/subscriptions');

        $definition = $this->requirePlan($plan, '/api/v1/subscriptions');

        $now = new DateTimeImmutable;
        $end = $now->modify('+1 month');

        $subscription = new Subscription(
            plan: $plan,
            status: 'active',
            periodStart: $now,
            periodEnd: $end,
            renewsAt: $end,
        );

        $this->subscriptions[$org] = $subscription;

        $intent = $definition->priceMinor > 0
            ? new PaymentIntent(
                gateway: 'sandbox',
                publishableKey: 'pk_test_'.$org,
                clientSecret: 'secret_'.$org,
                status: 'requires_confirmation',
                reference: 'pi_'.(++$this->intentSeq),
            )
            : null;

        return new SubscriptionResult($subscription, $intent);
    }

    public function previewChange(string $org, string $plan): ChangePreview
    {
        $this->guard('/api/v1/subscriptions/'.$org.'/preview');

        $target = $this->requirePlan($plan, '/api/v1/subscriptions/'.$org.'/preview');

        $current = $this->subscriptions[$org] ?? null;
        $currentPlan = $current !== null ? ($this->plans[$current->plan] ?? null) : null;
        $credit = $currentPlan !== null ? $currentPlan->priceMinor : 0;
        $dueNow = max(0, $target->priceMinor - $credit);

        $lines = [new PreviewLine('Subscribe to '.$target->name, $target->priceMinor)];

        if ($currentPlan !== null && $credit > 0) {
            $lines[] = new PreviewLine('Credit for unused '.$currentPlan->name, -$credit);
        }

        return new ChangePreview(
            dueNowMinor: $dueNow,
            creditMinor: $credit,
            newRecurringMinor: $target->priceMinor,
            effectiveAt: new DateTimeImmutable,
            lines: $lines,
        );
    }

    public function changePlan(string $org, string $plan): Subscription
    {
        $this->guard('/api/v1/subscriptions/'.$org.'/change');

        $this->requirePlan($plan, '/api/v1/subscriptions/'.$org.'/change');
        $current = $this->requireSubscription($org, '/api/v1/subscriptions/'.$org.'/change');

        $updated = new Subscription(
            plan: $plan,
            status: 'active',
            periodStart: $current->periodStart,
            periodEnd: $current->periodEnd,
            renewsAt: $current->periodEnd,
        );

        $this->subscriptions[$org] = $updated;

        return $updated;
    }

    public function cancel(string $org, bool $atPeriodEnd): Subscription
    {
        $this->guard('/api/v1/subscriptions/'.$org.'/cancel');

        $current = $this->requireSubscription($org, '/api/v1/subscriptions/'.$org.'/cancel');

        $updated = new Subscription(
            plan: $current->plan,
            status: $atPeriodEnd ? 'active' : 'canceled',
            periodStart: $current->periodStart,
            periodEnd: $atPeriodEnd ? $current->periodEnd : new DateTimeImmutable,
            renewsAt: null,
        );

        $this->subscriptions[$org] = $updated;

        return $updated;
    }

    public function usage(string $org): UsageSummary
    {
        $this->guard('/api/v1/usage/'.$org);

        $now = new DateTimeImmutable;

        return new UsageSummary(
            $this->usage[$org] ?? [],
            new BillingPeriod($now->modify('-15 days'), $now->modify('+15 days')),
        );
    }

    public function invoices(string $org): array
    {
        $this->guard('/api/v1/invoices/'.$org);

        return $this->invoices[$org] ?? [];
    }

    public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null): CheckoutSession
    {
        $path = '/api/v1/checkout-sessions';
        $this->guard($path);

        // The real service 404s an unknown plan; deny-by-default before minting a session.
        $this->requirePlan($plan, $path);

        $now = new DateTimeImmutable;

        return new CheckoutSession(
            url: 'https://billing.example/checkout/'.rawurlencode($org).'/'.(++$this->sessionSeq),
            expiresAt: $this->sessionsExpired ? $now->modify('-1 minute') : $now->modify('+30 minutes'),
        );
    }

    public function createPortalSession(string $org, string $returnUrl): PortalSession
    {
        $this->guard('/api/v1/portal-sessions');

        return new PortalSession(url: 'https://billing.example/portal/'.rawurlencode($org).'/'.(++$this->sessionSeq));
    }

    public function createSetupIntent(string $org): SetupIntent
    {
        $this->guard('/api/v1/setup-intents');

        $seq = ++$this->intentSeq;

        return new SetupIntent(
            gateway: 'sandbox',
            publishableKey: 'pk_test_'.$org,
            clientSecret: 'seti_secret_'.$org.'_'.$seq,
            status: 'requires_action',
            reference: 'seti_'.$seq,
        );
    }

    public function createPaymentIntent(string $org, ?string $reference = null, ?int $amountMinor = null, ?string $currency = null): PaymentIntent
    {
        $this->guard('/api/v1/payment-intents');

        if ($reference === null && $amountMinor === null) {
            throw new InvalidArgumentException('createPaymentIntent requires either a reference or an amount.');
        }

        $seq = ++$this->intentSeq;

        return new PaymentIntent(
            gateway: 'sandbox',
            publishableKey: 'pk_test_'.$org,
            clientSecret: 'pi_secret_'.$org.'_'.$seq,
            status: 'requires_action',
            reference: $reference ?? 'pi_'.$seq,
        );
    }

    public function paymentMethods(string $org): array
    {
        $this->guard('/api/v1/payment-methods/'.$org);

        return $this->paymentMethods[$org] ?? [];
    }

    public function setDefaultPaymentMethod(string $org, string $id): void
    {
        $path = '/api/v1/payment-methods/'.$org.'/default';
        $this->guard($path);

        $methods = $this->paymentMethods[$org] ?? [];
        $found = false;
        $updated = [];

        foreach ($methods as $method) {
            $isThis = $method->id === $id;
            $found = $found || $isThis;

            $updated[] = new PaymentMethod(
                id: $method->id,
                brand: $method->brand,
                last4: $method->last4,
                expMonth: $method->expMonth,
                expYear: $method->expYear,
                isDefault: $isThis,
            );
        }

        if (! $found) {
            // The real service 404s an unknown method; deny-by-default.
            throw TransportException::status($path, 404);
        }

        $this->paymentMethods[$org] = $updated;
    }

    public function removePaymentMethod(string $org, string $id): void
    {
        $path = '/api/v1/payment-methods/'.$org.'/'.$id;
        $this->guard($path);

        $methods = $this->paymentMethods[$org] ?? [];
        $remaining = array_values(array_filter(
            $methods,
            static fn (PaymentMethod $method): bool => $method->id !== $id,
        ));

        if (count($remaining) === count($methods)) {
            throw TransportException::status($path, 404);
        }

        $this->paymentMethods[$org] = $remaining;
    }

    private function requirePlan(string $plan, string $path): Plan
    {
        $definition = $this->plans[$plan] ?? null;

        if ($definition === null) {
            // The real service 404s an unknown plan; deny-by-default.
            throw TransportException::status($path, 404);
        }

        return $definition;
    }

    private function requireSubscription(string $org, string $path): Subscription
    {
        $current = $this->subscriptions[$org] ?? null;

        if ($current === null) {
            throw TransportException::status($path, 404);
        }

        return $current;
    }

    private function guard(string $endpoint): void
    {
        if ($this->down) {
            throw TransportException::unreachable($endpoint, new RuntimeException('management API is down (test outage)'));
        }
    }
}
