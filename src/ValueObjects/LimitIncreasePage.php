<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

readonly class LimitIncreasePage
{
    /** @param list<LimitIncrease> $data */
    public function __construct(public array $data = [], public int $currentPage = 1, public int $lastPage = 1) {}
}
