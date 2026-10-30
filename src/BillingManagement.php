<?php

declare(strict_types=1);

namespace Cbox\Billing\Client;

use Cbox\Billing\Client\Contracts\BundleTransport;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\SubscriptionTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\ValueObjects\CancelPreview;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\CheckoutSession;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\OrganizationBundles;
use Cbox\Billing\Client\ValueObjects\PaymentIntent;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PortalSession;
use Cbox\Billing\Client\ValueObjects\SetupIntent;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionChange;
use Cbox\Billing\Client\ValueObjects\SubscriptionPage;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;
use DateTimeInterface;
use Generator;

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
     * Optional since 0.4.3: `purchaser_type` (who the customer buys as, where that can exempt
     * the sale: the tax engine's PurchaserType values, such as `state_or_local_government`,
     * `diplomatic_or_consular` or `public_body`; it never exempts by itself), `ean_number` (the
     * 13-digit GLN a Danish public body is invoiced to) and `order_reference` (the purchase
     * order or contact person printed on every invoice). An older server ignores them.
     *
     * @param  array{name: string, billing_email?: string|null, billing_country?: string|null, billing_currency?: string|null, purchaser_type?: string|null, ean_number?: string|null, order_reference?: string|null}  $attributes
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
     * Reactivate `org`'s subscription: resume a pause, undo a scheduled period-end cancel, or
     * bring back one canceled within the win-back window.
     *
     * @throws BillingRefused when billing refuses (no subscription → 404, not reactivatable → 409)
     * @throws BillingUnavailable when billing cannot answer
     * @throws UnsupportedOperation when the transport does not implement {@see SubscriptionTransport}
     */
    public function reactivate(string $org): Subscription
    {
        return $this->subscriptionTransport(__FUNCTION__)->reactivate($org);
    }

    /**
     * The cross-product bundle benefits on `org`'s subscriptions and the offers to show it
     * ("Included with your Cbox Billing", "Add Cbox Tax, free with your Billing plan"), with copy
     * in `$locale` (the customer's own when null), narrowed to `$product` (a product-bound token
     * is narrowed to its own).
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     * @throws UnsupportedOperation when the transport does not implement {@see BundleTransport}
     */
    public function bundles(string $org, ?string $product = null, ?string $locale = null): OrganizationBundles
    {
        return $this->bundleTransport(__FUNCTION__)->bundles($org, $product, $locale);
    }

    /**
     * What cancelling `org`'s subscription would do, before the customer confirms: when it ends,
     * and the bundle benefits the organization's other subscriptions lose (from when, at what new
     * price). Show {@see CancelPreview::$consequences} before the cancel button. Changes nothing.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     * @throws UnsupportedOperation when the transport does not implement {@see BundleTransport}
     */
    public function previewCancel(string $org, bool $atPeriodEnd = true, ?string $locale = null): CancelPreview
    {
        return $this->bundleTransport(__FUNCTION__)->previewCancel($org, $atPeriodEnd, $locale);
    }

    /**
     * One page of the subscription change feed, oldest change first: every subscription changed
     * at or after `$updatedSince` (all when null), continued from `$cursor`. Use it to mirror
     * subscriber plans and statuses in a few calls instead of one `subscription()` per
     * organization. Key your records on {@see SubscriptionChange::$organizationRef}.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     * @throws UnsupportedOperation when the transport does not implement {@see SubscriptionTransport}
     */
    public function subscriptions(?DateTimeInterface $updatedSince = null, ?string $cursor = null, ?int $limit = null): SubscriptionPage
    {
        return $this->subscriptionTransport(__FUNCTION__)->subscriptions($updatedSince, $cursor, $limit);
    }

    /**
     * Every subscription changed at or after `$updatedSince`, reading page after page as the
     * caller iterates. Pages are fetched lazily, so breaking out early stops the requests; a
     * failure on a later page throws from inside the loop.
     *
     * @return Generator<int, SubscriptionChange, mixed, void>
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     * @throws UnsupportedOperation when the transport does not implement {@see SubscriptionTransport}
     */
    public function allSubscriptions(?DateTimeInterface $updatedSince = null, ?int $pageSize = null): Generator
    {
        // Checked here, not inside the generator, so a missing contract throws at the call
        // rather than on the first iteration.
        return $this->drain($this->subscriptionTransport(__FUNCTION__), $updatedSince, $pageSize);
    }

    /**
     * @return Generator<int, SubscriptionChange, mixed, void>
     */
    private function drain(SubscriptionTransport $transport, ?DateTimeInterface $updatedSince, ?int $pageSize): Generator
    {
        $cursor = null;

        do {
            $page = $transport->subscriptions($updatedSince, $cursor, $pageSize);

            // Plain `yield`, not `yield from`: each page's list keys restart at 0, and repeated
            // keys would collapse rows in iterator_to_array().
            foreach ($page->data as $row) {
                yield $row;
            }

            $cursor = $page->nextCursor;
        } while ($page->hasMore && $cursor !== null);
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

    /**
     * The transport as a {@see SubscriptionTransport}. A custom {@see ManagementTransport}
     * written against 0.4.1 does not have the 0.4.2 subscription endpoints; that is a clear
     * error, not a silent no-op.
     */
    private function subscriptionTransport(string $method): SubscriptionTransport
    {
        if (! $this->transport instanceof SubscriptionTransport) {
            throw UnsupportedOperation::missingContract($this->transport, SubscriptionTransport::class, $method);
        }

        return $this->transport;
    }

    private function bundleTransport(string $method): BundleTransport
    {
        if (! $this->transport instanceof BundleTransport) {
            throw UnsupportedOperation::missingContract($this->transport, BundleTransport::class, $method);
        }

        return $this->transport;
    }
}
