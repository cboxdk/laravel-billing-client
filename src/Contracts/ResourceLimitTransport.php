<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\ValueObjects\LimitIncrease;
use Cbox\Billing\Client\ValueObjects\LimitIncreaseInput;
use Cbox\Billing\Client\ValueObjects\LimitIncreasePage;
use Cbox\Billing\Client\ValueObjects\ResourceLimit;

/** Optional management contract. A resource grant never grants monetary credit. */
interface ResourceLimitTransport
{
    /** @return array<string, ResourceLimit> */
    public function resourceLimits(string $org, ?string $product = null): array;

    public function limitIncreaseRequests(string $org, int $page = 1, ?string $product = null): LimitIncreasePage;

    public function requestLimitIncrease(string $org, LimitIncreaseInput $request, ?string $product = null): LimitIncrease;

    public function cancelLimitIncrease(string $org, int $requestId, ?string $product = null): LimitIncrease;
}
