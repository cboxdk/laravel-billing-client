<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * The result of leasing a slice of allowance from billing (`POST /api/v1/leases`):
 * `granted` units the node may now spend locally (0 when the org's allowance is
 * exhausted). `expiresAt` is a millisecond epoch after which the lease should be
 * considered returned by billing and re-acquired locally.
 */
readonly class LeaseGrant
{
    public function __construct(
        public string $leaseId,
        public string $org,
        public string $meter,
        public int $granted,
        public ?int $expiresAt = null,
    ) {}

    /** A grant of nothing — allowance exhausted centrally. */
    public static function empty(string $org, string $meter): self
    {
        return new self('', $org, $meter, 0);
    }
}
