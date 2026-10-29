<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionPage;
use DateTimeInterface;

/**
 * The subscription endpoints added to the management API in 0.4.2: the change feed and
 * reactivate. A separate contract rather than new methods on {@see ManagementTransport}, so a
 * transport written against 0.4.1 keeps compiling. {@see HttpManagementTransport} and
 * {@see FakeManagementTransport} implement both; {@see BillingManagement} uses this one when
 * its transport has it and otherwise throws an UnsupportedOperation. The two may merge into
 * ManagementTransport in the next minor release.
 *
 * Deny-by-default like {@see ManagementTransport}: a call that cannot complete throws a
 * {@see TransportException}.
 */
interface SubscriptionTransport
{
    /**
     * One page of the subscription change feed (`GET /api/v1/subscriptions`), oldest change
     * first: every subscription changed at or after `$updatedSince` (all of them when null),
     * continued from `$cursor`, at most `$limit` rows (the server's default when null).
     *
     * @throws TransportException a BillingRefused when billing says no, a BillingUnavailable when it cannot answer
     */
    public function subscriptions(?DateTimeInterface $updatedSince = null, ?string $cursor = null, ?int $limit = null): SubscriptionPage;

    /**
     * Reactivate `org`'s subscription (`POST /api/v1/subscriptions/{org}/reactivate`): resume a
     * pause, undo a scheduled period-end cancel, or bring back one canceled within the win-back
     * window. On a subscription that is already active the server answers 409.
     *
     * @throws TransportException a BillingRefused when billing says no (404 with no subscription, 409 when it cannot be reactivated), a BillingUnavailable when it cannot answer
     */
    public function reactivate(string $org): Subscription;
}
