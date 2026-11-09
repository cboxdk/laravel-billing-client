<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Exceptions\BillingClientException;
use Cbox\Billing\Client\Kept\CacheKeptAnswers;
use Cbox\Billing\Client\ValueObjects\KeptAnswer;

/**
 * Billing's answers, kept so that no page waits on Billing (stale-while-revalidate). The
 * default implementation is {@see CacheKeptAnswers}; its rules:
 *
 *  - Every answer, yes and no alike, is kept with the time it was asked. It is fresh for
 *    `kept.fresh_for` seconds.
 *  - In an HTTP request with a safe method (GET, HEAD, OPTIONS), a kept answer is returned at
 *    once, even a stale one, and a stale one queues one background refresh.
 *  - A change (POST, PUT, PATCH, DELETE), a caller that passes `acting: true`, and work
 *    outside an HTTP request (a job, a command) never act on a stale answer: they ask now and
 *    keep the answer. A fresh one is used as is.
 *  - Nothing kept, or an answer older than `kept.max_age`: ask now.
 *  - Billing failing while an answer is kept, on a safe request: the kept answer is served,
 *    however old (up to `kept.keep_for`). Otherwise the failure is thrown, as without keeping.
 */
interface KeptAnswers
{
    /**
     * The answer to `$question` under the rules above.
     *
     * @return array<array-key, mixed>|string|int|float|bool|null
     *
     * @throws BillingClientException when Billing must be asked and cannot answer
     */
    public function answer(KeptQuestion $question, bool $acting = false): array|string|int|float|bool|null;

    /**
     * Ask Billing now and keep the answer. What a first visit waits for, and what the
     * background refresh does.
     *
     * @return array<array-key, mixed>|string|int|float|bool|null
     *
     * @throws BillingClientException when Billing cannot answer
     */
    public function refresh(KeptQuestion $question): array|string|int|float|bool|null;

    /** The kept answer as it stands, or null when nothing valid is kept. Never asks Billing. */
    public function read(KeptQuestion $question): ?KeptAnswer;

    /**
     * Keep `$answer` as if Billing had just given it: the app itself admitted someone, or a
     * webhook told it what changed. The value must be plain data the question accepts.
     *
     * @param  array<array-key, mixed>|string|int|float|bool|null  $answer
     */
    public function keep(KeptQuestion $question, array|string|int|float|bool|null $answer): void;

    /** Drop the kept answer: the next read asks Billing. */
    public function forget(KeptQuestion $question): void;
}
