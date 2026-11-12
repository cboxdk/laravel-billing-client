<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\OrganizationProfile;

/**
 * The read-only organization profile, added in 0.6.3. A separate contract, like
 * {@see PortalSectionTransport}, so a transport written against an earlier release keeps
 * compiling. {@see HttpManagementTransport} and {@see FakeManagementTransport} implement it;
 * {@see BillingManagement} throws an UnsupportedOperation when its transport does not.
 */
interface OrganizationReadTransport
{
    /**
     * `GET /v1/organizations/{org}`: what billing stores for the organization, as the customer
     * sees it. Never creates the organization; an unknown one is a BillingRefused with status 404.
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function organizationProfile(string $org): OrganizationProfile;
}
