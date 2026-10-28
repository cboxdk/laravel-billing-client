<?php

declare(strict_types=1);

namespace Cbox\Billing\Client;

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\CheckoutSession;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PortalSession;
use Cbox\Billing\Client\ValueObjects\SetupIntent;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;

/**
 * The app-facing self-service billing client — what a product app calls to let ITS
 * users manage their own subscription: browse plans, subscribe, preview/apply a plan
 * change, cancel, and read usage and invoices. It is the management-API analogue of
 * the enforcement {@see BillingClient}: a thin, typed seam over the swappable
 * {@see ManagementTransport} that returns immutable value objects and, being
 * deny-by-default, surfaces a {@see TransportException} on any outage rather than a
 * fabricated result — a caller can never mistake an unreachable service for an answer.
 */
class BillingManagement
{
    public function __construct(
        private readonly ManagementTransport $transport,
    ) {}

    /**
     * List the subscribable plans.
     *
     * @return list<Plan>
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function plans(?string $currency = null): array
    {
        return $this->transport->plans($currency);
    }

    /**
     * Idempotently provision the organization this platform bills for. Safe to call
     * before every subscribe/checkout; currency is only applied on create.
     *
     * @param  array{name: string, billing_email?: string|null, billing_country?: string|null, billing_currency?: string|null}  $attributes
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function ensureOrganization(string $org, array $attributes): void
    {
        $this->transport->ensureOrganization($org, $attributes);
    }

    /**
     * The organization's current subscription, or null when it has none.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function subscription(string $org): ?Subscription
    {
        return $this->transport->subscription($org);
    }

    /**
     * Subscribe `org` to `plan`, returning the subscription and any payment intent to
     * confirm.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function subscribe(string $org, string $plan): SubscriptionResult
    {
        return $this->transport->subscribe($org, $plan);
    }

    /**
     * Preview switching `org` to `plan` without applying it — the due-now, credit, new
     * recurring amount, and itemized lines to show the user before they commit.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function previewChange(string $org, string $plan): ChangePreview
    {
        return $this->transport->previewChange($org, $plan);
    }

    /**
     * Apply a plan change for `org`, returning the resulting change preview.
     *
     * Returns a {@see ChangePreview}, NOT a subscription: `/change` answers with the same
     * envelope as `/preview` — what is due now, what was credited, the new recurring amount and
     * the proration lines. This signature previously claimed a Subscription that the endpoint has
     * never returned, so the method threw on every call.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function changePlan(string $org, string $plan): ChangePreview
    {
        return $this->transport->changePlan($org, $plan);
    }

    /**
     * Cancel `org`'s subscription. By default it runs to the end of the paid period, the
     * same default the server applies; pass `atPeriodEnd: false` to end it now.
     *
     * Before 0.4.0 the default was immediate, which cut a paying customer off mid-period.
     *
     * @throws BillingRefused when billing refuses (no subscription → 404)
     * @throws BillingUnavailable when billing cannot answer
     */
    public function cancel(string $org, bool $atPeriodEnd = true): Subscription
    {
        return $this->transport->cancel($org, $atPeriodEnd);
    }

    /**
     * The organization's current-period usage per meter.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function usage(string $org): UsageSummary
    {
        return $this->transport->usage($org);
    }

    /**
     * The organization's invoice history.
     *
     * @return list<Invoice>
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function invoices(string $org): array
    {
        return $this->transport->invoices($org);
    }

    /**
     * Open a hosted-checkout session (ADR-0009 path A) for `org` to take up `plan`,
     * returning the `url` to redirect to and when it expires. Optionally price it in
     * `$currency`.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null): CheckoutSession
    {
        return $this->transport->createCheckoutSession($org, $plan, $returnUrl, $currency);
    }

    /**
     * Open a billing-portal session (ADR-0009 path A) for `org`, returning the `url` to
     * redirect the user to.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createPortalSession(string $org, string $returnUrl): PortalSession
    {
        return $this->transport->createPortalSession($org, $returnUrl);
    }

    /**
     * Create a gateway setup intent (ADR-0009 path B) so `org` can store a payment method
     * with no immediate charge. Returns the `{gateway, publishableKey, clientSecret}` the
     * front-end mounts the gateway element with.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createSetupIntent(string $org): SetupIntent
    {
        return $this->transport->createSetupIntent($org);
    }

    /**
     * Create a gateway payment intent (ADR-0009 path B) charging `org` — for either an
     * existing `$reference` (e.g. an open invoice) or an ad-hoc `$amountMinor` in
     * `$currency`; at least one must be given.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createPaymentIntent(string $org, ?string $reference = null, ?int $amountMinor = null, ?string $currency = null): PaymentIntent
    {
        return $this->transport->createPaymentIntent($org, $reference, $amountMinor, $currency);
    }

    /**
     * The organization's stored payment methods.
     *
     * @return list<PaymentMethod>
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function paymentMethods(string $org): array
    {
        return $this->transport->paymentMethods($org);
    }

    /**
     * Make payment method `$id` the default for `org`.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function setDefaultPaymentMethod(string $org, string $id): void
    {
        $this->transport->setDefaultPaymentMethod($org, $id);
    }

    /**
     * Remove payment method `$id` from `org`.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function removePaymentMethod(string $org, string $id): void
    {
        $this->transport->removePaymentMethod($org, $id);
    }
}
