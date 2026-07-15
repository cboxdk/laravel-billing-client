<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * One meter's slice of a multi-meter local reservation: `amount` units held on
 * `meter`. `backedByLease` records whether the hold actually decremented the local
 * lease — it is `false` only for a bucket admitted under a fail-OPEN outage (billing
 * unreachable, no local units). Such a hold still buffers usage on commit but must NOT
 * give units back on commit/release, because it never took any.
 *
 * A single-meter reservation is the degenerate one-bucket case of a set of these.
 */
readonly class BucketHold
{
    public function __construct(
        public string $meter,
        public int $amount,
        public bool $backedByLease = true,
    ) {}
}
