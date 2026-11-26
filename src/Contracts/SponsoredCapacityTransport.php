<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\CapacityAllocation;
use Cbox\Billing\Client\ValueObjects\CapacityClaim;

/**
 * Durable provider capacity, independent of customer plans and the local lease client.
 * Persist keys before calling. Ambiguous outcomes must be reconciled with the same keys;
 * neither a timeout nor a lookup 404 permits discarding a hold or generating a new key.
 */
interface SponsoredCapacityTransport
{
    public function reserve(string $agreement, string $claim, string $org, string $allocation, int $amount): CapacityClaim;

    public function claim(string $agreement, string $claim): CapacityClaim;

    public function commit(string $agreement, string $claim, int $actual): CapacityClaim;

    /** Only after reserved staging was safely aborted/deleted. */
    public function cancel(string $agreement, string $claim): CapacityClaim;

    public function allocation(string $agreement, string $allocation): CapacityAllocation;

    /** Only after physical deletion, with pending claims drained. */
    public function release(string $agreement, string $allocation, string $deletion): CapacityAllocation;
}
