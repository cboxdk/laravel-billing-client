<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One page of the subscription change feed (`GET /api/v1/subscriptions`), oldest change first.
 * When `hasMore` is true, pass `nextCursor` back to read the next page. Keep the `updatedAt` of
 * the last row you processed and pass it as `updatedSince` on the next sync to read only what
 * changed since.
 */
readonly class SubscriptionPage
{
    /**
     * @param  list<SubscriptionChange>  $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore = false,
        public ?string $nextCursor = null,
    ) {}
}
