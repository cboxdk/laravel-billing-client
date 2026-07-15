<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;

/**
 * The remote seam to the Cbox Billing MANAGEMENT API — the self-service surface a
 * product app calls to let ITS users read plans, subscribe, change or cancel a plan,
 * and read usage and invoices. Every method maps one-to-one onto a management HTTP
 * endpoint; {@see HttpManagementTransport} speaks real HTTP and
 * {@see FakeManagementTransport} stands in for tests. Depend on this interface, never
 * a concrete transport, so the management surface is testable offline and swappable.
 *
 * Deny-by-default: a method that cannot complete — network error, non-2xx, or
 * malformed body — throws a {@see TransportException} and never fabricates a success,
 * so a caller cannot mistake an outage for an entitlement.
 */
interface ManagementTransport
{
    /**
     * List the subscribable plans (`GET /api/v1/plans`).
     *
     * @return list<Plan>
     *
     * @throws TransportException on an infrastructure fault
     */
    public function plans(): array;

    /**
     * The organization's current subscription (`GET /api/v1/subscriptions/{org}`), or
     * null when it has none.
     *
     * @throws TransportException on an infrastructure fault
     */
    public function subscription(string $org): ?Subscription;

    /**
     * Subscribe `org` to `plan` (`POST /api/v1/subscriptions`), returning the new
     * subscription and any payment intent to confirm.
     *
     * @throws TransportException on an infrastructure fault
     */
    public function subscribe(string $org, string $plan): SubscriptionResult;

    /**
     * Preview switching `org` to `plan` without applying it
     * (`POST /api/v1/subscriptions/{org}/preview`).
     *
     * @throws TransportException on an infrastructure fault
     */
    public function previewChange(string $org, string $plan): ChangePreview;

    /**
     * Change `org` to `plan` (`POST /api/v1/subscriptions/{org}/change`), returning the
     * updated subscription.
     *
     * @throws TransportException on an infrastructure fault
     */
    public function changePlan(string $org, string $plan): Subscription;

    /**
     * Cancel `org`'s subscription (`POST /api/v1/subscriptions/{org}/cancel`);
     * `$atPeriodEnd` cancels at period end rather than immediately.
     *
     * @throws TransportException on an infrastructure fault
     */
    public function cancel(string $org, bool $atPeriodEnd): Subscription;

    /**
     * The organization's current-period usage per meter (`GET /api/v1/usage/{org}`).
     *
     * @throws TransportException on an infrastructure fault
     */
    public function usage(string $org): UsageSummary;

    /**
     * The organization's invoice history (`GET /api/v1/invoices/{org}`).
     *
     * @return list<Invoice>
     *
     * @throws TransportException on an infrastructure fault
     */
    public function invoices(string $org): array;
}
