<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;

/** Optional capability: stable caller-owned identities for subscription mutations. */
interface IdempotentSubscriptionTransport
{
    public function subscribeIdempotently(string $org, string $plan, string $idempotencyKey): SubscriptionResult;

    public function changePlanIdempotently(string $org, string $plan, string $idempotencyKey): ChangePreview;

    public function cancelIdempotently(string $org, bool $atPeriodEnd, string $idempotencyKey): Subscription;

    public function reactivateIdempotently(string $org, string $idempotencyKey): Subscription;
}
