<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\CheckoutSession;

/**
 * A hosted checkout with a promo code, added in 0.6.3. A separate contract, like
 * {@see PortalSectionTransport}, so a transport written against an earlier release keeps
 * compiling. {@see HttpManagementTransport} and {@see FakeManagementTransport} implement it;
 * {@see BillingManagement} throws an UnsupportedOperation when a coupon is asked of a transport
 * that does not.
 */
interface CouponCheckoutTransport
{
    /**
     * `POST /v1/checkout-sessions` with `coupon` when one is given. Billing validates the code
     * against the plan, the currency and the organization before it mints the session, and
     * redeems it when the checkout completes.
     *
     * @throws TransportException a BillingRefused (422) when billing will not apply the code, a BillingUnavailable when it cannot answer
     */
    public function createCheckoutSession(string $org, string $plan, string $returnUrl, ?string $currency = null, ?string $idempotencyKey = null, ?string $coupon = null): CheckoutSession;
}
