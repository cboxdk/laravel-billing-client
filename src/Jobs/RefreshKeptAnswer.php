<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Jobs;

use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Contracts\KeptQuestion;
use Cbox\Billing\Client\Support\BackgroundJobs;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Asks Billing again about one question whose kept answer has gone stale, so the page that
 * noticed did not have to wait ({@see KeptAnswers}). Unique per question while queued: a burst
 * of pages queues one question. One try: when Billing is still away the kept answer stays, the
 * failure is reported, and the claim it leaves behind is the backoff before the next try.
 */
class RefreshKeptAnswer implements RunsOnBillingQueue, ShouldBeUnique, ShouldQueue
{
    use RunsInBackground;

    /** Seconds a queued (or failed) refresh blocks another one for the same question. */
    public const int UNIQUE_FOR = 60;

    public int $tries = 1;

    public int $uniqueFor = self::UNIQUE_FOR;

    public function __construct(public readonly KeptQuestion $question) {}

    public function uniqueId(): string
    {
        return hash('sha256', $this->question->key());
    }

    public function handle(KeptAnswers $answers, BackgroundJobs $jobs): void
    {
        try {
            $answers->refresh($this->question);
        } catch (Throwable $e) {
            // Billing away: the kept answer stays, and the next stale page after the backoff asks again.
            $jobs->report($e);
        }
    }
}
