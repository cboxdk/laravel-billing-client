<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\CancelPreview;
use Cbox\Billing\Client\ValueObjects\OrganizationBundles;

/**
 * The bundle endpoints added to the management API in 0.4.3: the bundles an app shows and the
 * cancel preview. A separate contract, like {@see SubscriptionTransport}, so a transport written
 * against an earlier release keeps compiling. {@see HttpManagementTransport} and
 * {@see FakeManagementTransport} implement it; {@see BillingManagement} throws an
 * UnsupportedOperation when its transport does not.
 */
interface BundleTransport
{
    /**
     * The bundle benefits on `org`'s subscriptions and the offers to show
     * (`GET /api/v1/organizations/{org}/bundles`), narrowed to `$product` (a product-bound token
     * is narrowed to its own), with copy in `$locale` (the customer's own when null).
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function bundles(string $org, ?string $product = null, ?string $locale = null): OrganizationBundles;

    /**
     * What cancelling `org`'s subscription would do (`POST /api/v1/subscriptions/{org}/cancel/preview`):
     * when it ends, and the bundle benefits the organization's other subscriptions lose. Changes
     * nothing.
     *
     * @throws TransportException a BillingRefused when billing says no (404 with no subscription), a BillingUnavailable when it cannot answer
     */
    public function previewCancel(string $org, bool $atPeriodEnd = true, ?string $locale = null): CancelPreview;
}
