<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One line of a plan-change preview: a human-readable `description` and its signed
 * `amountMinor` in the smallest currency unit (a credit is negative, a charge
 * positive). The lines sum to the preview's net due-now.
 */
readonly class PreviewLine
{
    public function __construct(
        public string $description,
        public int $amountMinor,
    ) {}
}
