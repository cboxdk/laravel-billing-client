<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Exceptions\BillingClientException;
use Illuminate\Contracts\Container\Container;

/**
 * One question an app asks Billing whose answer is kept ({@see KeptAnswers}): who it is about
 * ({@see key()}), how to ask it now ({@see ask()}) and what a kept answer may look like
 * ({@see accepts()}).
 *
 * A question is queued with the background refresh, so it holds only plain, serializable
 * values (strings, ints): never a model, a closure or a connection. Its answer is plain data
 * too, arrays and scalars, because it is kept in the cache store and read back by another
 * process.
 */
interface KeptQuestion
{
    /** A stable key for this exact question: the same question always gives the same key. */
    public function key(): string;

    /**
     * Ask Billing now. The answer is plain: an array of arrays and scalars, a scalar or null.
     *
     * @return array<array-key, mixed>|string|int|float|bool|null
     *
     * @throws BillingClientException when Billing does not answer or refuses
     */
    public function ask(Container $app): array|string|int|float|bool|null;

    /** True when a value read back from the cache is an answer to this question. */
    public function accepts(mixed $value): bool;
}
