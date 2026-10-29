<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Stores\CacheAllowanceSnapshots;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;

/**
 * What the client last knew about an organization's allowance, so it can keep enforcing
 * while billing is unreachable ({@see FailurePolicy::LastKnown}).
 *
 * Per (org, meter) it keeps the last {@see Entitlement} billing sent, the units billing
 * has leased to this client (`drawn`, which mirrors billing's own count of outstanding
 * leases and so includes any lease not yet spent), and the units admitted without a
 * lease during an outage. The headroom past the local lease is
 * `allowance − drawn − admitted`: the allowance minus what is used, with the unspent
 * lease spent first through the normal path.
 *
 * Only what this client saw is known. Clients that share the store (the same cache)
 * share the view; a client with its own store does not see the others' leases. The
 * default ({@see CacheAllowanceSnapshots}) lives in the cache that holds the lease.
 */
interface AllowanceSnapshots
{
    /** Keep `$entitlements` as the last known entitlement set for its organization. */
    public function remember(Entitlements $entitlements): void;

    /**
     * True at most once per refresh interval for `$org`: the caller should read the
     * entitlements now. Claiming it is atomic, so concurrent refills read them once.
     */
    public function claimRefresh(string $org): bool;

    /** The last known entitlement for (org, meter), or null when none was ever seen. */
    public function entitlement(string $org, string $meter): ?Entitlement;

    /**
     * Record a lease billing answered: it was asked for `$requested` and granted
     * `$granted`. A short grant on a capped meter means billing has nothing left.
     */
    public function recordLease(string $org, string $meter, int $requested, int $granted): void;

    /**
     * Units of a capped meter that may still be admitted without a lease. Zero when the
     * entitlement is unknown or the meter is not capped (an uncapped meter has no cap to
     * report; see {@see Entitlement::isCapped()}).
     */
    public function headroom(string $org, string $meter): int;

    /**
     * Admit `$units` without a lease. Counts them and returns true when the last known
     * entitlement allows it (always, for an uncapped meter); returns false and counts
     * nothing when it does not or no entitlement is known. Atomic: under concurrency it
     * can only over-refuse, never over-admit.
     */
    public function admit(string $org, string $meter, int $units): bool;

    /** Return `$units` an earlier {@see admit()} counted but the request did not use. */
    public function giveBack(string $org, string $meter, int $units): void;
}
