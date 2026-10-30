<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\Plan;

/**
 * The catalog priced for a buyer, added in 0.4.6: `GET /api/v1/plans?org=&country=`. A separate
 * contract, like {@see SubscriptionTransport}, so a transport written against an earlier release
 * keeps compiling. {@see HttpManagementTransport} and {@see FakeManagementTransport} implement it;
 * {@see BillingManagement::plans()} throws an UnsupportedOperation when it is asked for a buyer
 * and its transport does not.
 */
interface TaxedPlanTransport
{
    /**
     * The plans, each with the tax `$org` pays on it (when it has a billing country), else a
     * consumer in `$country`, else a consumer in the seller's own country. Priced in `$currency`
     * when given, else `$org`'s account currency.
     *
     * @return list<Plan>
     *
     * @throws TransportException a BillingRefused when billing says no (a 404 for an unknown org), a BillingUnavailable when it cannot answer
     */
    public function plansFor(?string $currency = null, ?string $org = null, ?string $country = null): array;
}
