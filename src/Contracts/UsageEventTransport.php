<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\UsageEvent;
use Cbox\Billing\Client\ValueObjects\UsageEventReceipt;

/** Optional raw-event contract; never also report these quantities as cumulative totals. */
interface UsageEventTransport
{
    /** @param list<UsageEvent> $events Between 1 and 1,000 events with stable, distinct IDs. */
    public function ingestUsageEvents(string $org, array $events): UsageEventReceipt;
}
