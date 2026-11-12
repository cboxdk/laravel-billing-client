<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

/**
 * A {@see KeptQuestion} whose answers are not all kept alike. The admission check is the case
 * in point: a "yes" may be served for weeks while it is refreshed in the background, but a
 * "no" must not, or somebody Billing has just let in would still be turned away.
 */
interface JudgesKeptAnswer extends KeptQuestion
{
    /** Seconds `$value` is used as is, or null for `kept.fresh_for`. */
    public function freshFor(mixed $value): ?int;

    /**
     * Whether a stale `$value` may be served to a safe request while a refresh is queued. When
     * false, a stale answer is asked again in the request, and served only if Billing fails.
     */
    public function servesStale(mixed $value): bool;
}
