<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

readonly class OrganizationFeatures
{
    /** @param array<string, int|string|true> $grants */
    public function __construct(public bool $suspended, private array $grants) {}

    public function value(string $key): int|string|true|null
    {
        return $this->suspended ? null : ($this->grants[$key] ?? null);
    }
}
