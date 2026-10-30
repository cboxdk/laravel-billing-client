<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\OrganizationProfile;

/**
 * The organization upsert with billing's answer, added in 0.4.6. A separate contract, like
 * {@see BundleTransport}, so a transport written against an earlier release keeps compiling.
 * {@see HttpManagementTransport} and {@see FakeManagementTransport} implement it;
 * {@see BillingManagement} throws an UnsupportedOperation when its transport does not.
 */
interface OrganizationProfileTransport
{
    /**
     * Creates or updates `org` (`PUT /api/v1/organizations/{org}`) and returns what billing stores.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function upsertOrganization(string $org, array $attributes): OrganizationProfile;
}
