<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
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
 * The remote seam to the Cbox Billing MANAGEMENT API — the self-service surface a
 * product app calls to let ITS users read plans, subscribe, change or cancel a plan,
 * read usage and invoices, and collect payment either by redirecting to a hosted
 * checkout/portal session (ADR-0009 path A) or by driving an embedded gateway element
 * off a setup/payment intent and managing the stored payment methods (path B). Every
 * method maps one-to-one onto a management HTTP endpoint; {@see HttpManagementTransport}
 * speaks real HTTP and
 * {@see FakeManagementTransport} stands in for tests. Depend on this interface, never
 * a concrete transport, so the management surface is testable offline and swappable.
 *
 * Deny-by-default: a method that cannot complete — network error, non-2xx, or
 * malformed body — throws a {@see TransportException} and never fabricates a success,
 * so a caller cannot mistake an outage for an entitlement. It throws the subclass that
 * fits the cause: {@see BillingRefused} for a 4xx other than 408/429,
 * {@see BillingUnavailable} for everything else.
 */
interface ManagementTransport
{
    /**
     * List the subscribable plans (`GET /api/v1/plans`), priced in `$currency` when
     * given (ISO 4217), else the caller's account currency / service default.
     *
     * @return list<Plan>
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function plans(?string $currency = null): array;

    /**
     * Idempotently provision the organization this platform bills for
     * (`PUT /api/v1/organizations/{org}`). Safe to call before every subscribe /
     * checkout; `billing_currency` in `$attributes` is only applied on create
     * (the billing service's one-way currency lock).
     *
     * @param  array{name: string, billing_email?: string|null, billing_country?: string|null, billing_currency?: string|null, purchaser_type?: string|null, ean_number?: string|null, order_reference?: string|null}  $attributes
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function ensureOrganization(string $org, array $attributes): void;

    /**
     * The organization's current subscription (`GET /api/v1/subscriptions/{org}`), or
     * null when it has none. The server answers that case with a 404, which an
     * implementation must return as null rather than throw.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function subscription(string $org): ?Subscription;

    /**
     * Subscribe `org` to `plan` (`POST /api/v1/subscriptions`), returning the new
     * subscription and any payment intent to confirm.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function subscribe(string $org, string $plan): SubscriptionResult;

    /**
     * Preview switching `org` to `plan` without applying it
     * (`POST /api/v1/subscriptions/{org}/preview`).
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function previewChange(string $org, string $plan): ChangePreview;

    /**
     * Change `org` to `plan` (`POST /api/v1/subscriptions/{org}/change`), returning the
     * updated subscription.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function changePlan(string $org, string $plan): ChangePreview;

    /**
     * Cancel `org`'s subscription (`POST /api/v1/subscriptions/{org}/cancel`);
     * `$atPeriodEnd` cancels at period end rather than immediately.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function cancel(string $org, bool $atPeriodEnd): Subscription;

    /**
     * The organization's current-period usage per meter (`GET /api/v1/usage/{org}`).
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function usage(string $org): UsageSummary;

    /**
     * The organization's invoice history (`GET /api/v1/invoices/{org}`).
     *
     * @return list<Invoice>
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function invoices(string $org): array;

    /**
     * Open a hosted-checkout session (`POST /api/v1/checkout-sessions`) for `org` to take
     * up `plan`, redirecting to `returnUrl` when done and optionally pricing in
     * `$currency`. Path A of ADR-0009: billing collects payment on its own pages.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null): CheckoutSession;

    /**
     * Open a billing-portal session (`POST /api/v1/portal-sessions`) for `org`, returning
     * to `returnUrl` when the user leaves. Path A of ADR-0009: billing hosts the
     * self-service portal.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createPortalSession(string $org, string $returnUrl): PortalSession;

    /**
     * Create a gateway setup intent (`POST /api/v1/setup-intents`) so `org` can store a
     * payment method with no immediate charge. Path B of ADR-0009: the front-end mounts
     * the gateway element with the returned `{gateway, publishableKey, clientSecret}`.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createSetupIntent(string $org): SetupIntent;

    /**
     * Create a gateway payment intent (`POST /api/v1/payment-intents`) charging `org`,
     * for either an existing `$reference` (e.g. an open invoice) or an ad-hoc
     * `$amountMinor` in `$currency` — at least one must be given. Path B of ADR-0009: the
     * front-end confirms the returned `clientSecret` and a webhook confirms settlement.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function createPaymentIntent(string $org, ?string $reference = null, ?int $amountMinor = null, ?string $currency = null): PaymentIntent;

    /**
     * The organization's stored payment methods
     * (`GET /api/v1/payment-methods/{org}`).
     *
     * @return list<PaymentMethod>
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function paymentMethods(string $org): array;

    /**
     * Make payment method `$id` the default for `org`
     * (`POST /api/v1/payment-methods/{org}/default`).
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function setDefaultPaymentMethod(string $org, string $id): void;

    /**
     * Remove payment method `$id` from `org`
     * (`DELETE /api/v1/payment-methods/{org}/{id}`).
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function removePaymentMethod(string $org, string $id): void;
}
