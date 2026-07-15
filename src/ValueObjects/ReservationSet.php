<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\BillingClient;

/**
 * A set of per-meter holds reserved together by {@see BillingClient::reserve()} when
 * given a `[meter => estimate]` map — the multi-dimensional analogue of a single
 * {@see Reservation}. Every bucket is taken from its OWN meter's local lease and the
 * set is all-or-nothing: if any meter cannot be satisfied the whole set is rolled
 * back and the reservation fails as one. Settled as a whole by commit/release, each
 * bucket returning its own leftover to its own slice.
 */
readonly class ReservationSet
{
    /**
     * @param  list<BucketHold>  $buckets
     */
    public function __construct(
        public string $id,
        public string $org,
        public array $buckets,
    ) {}

    /** The hold for `$meter`, or `null` when this set holds none. */
    public function bucket(string $meter): ?BucketHold
    {
        foreach ($this->buckets as $bucket) {
            if ($bucket->meter === $meter) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * The meters this set holds, in reservation order.
     *
     * @return list<string>
     */
    public function meters(): array
    {
        return array_map(static fn (BucketHold $bucket): string => $bucket->meter, $this->buckets);
    }

    /**
     * The lease-backed units held per meter — the amounts that must be reclaimed if
     * the reservation is abandoned. Fail-open (unbacked) buckets are excluded because
     * they took nothing from any slice.
     *
     * @return array<string, int>
     */
    public function backedHolds(): array
    {
        $holds = [];

        foreach ($this->buckets as $bucket) {
            if ($bucket->backedByLease && $bucket->amount > 0) {
                $holds[$bucket->meter] = ($holds[$bucket->meter] ?? 0) + $bucket->amount;
            }
        }

        return $holds;
    }
}
