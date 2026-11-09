<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Contracts\KeptQuestion;
use Cbox\Billing\Client\Exceptions\BillingClientException;
use Cbox\Billing\Client\Jobs\RefreshKeptAnswer;
use Cbox\Billing\Client\Support\BackgroundJobs;
use Cbox\Billing\Client\ValueObjects\KeptAnswer;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * {@see KeptAnswers} in the cache store. Each answer is stored as `{value, at}`: plain data and
 * the Unix time Billing gave it. What comes back is validated before it is used (shape, time,
 * and the question's own {@see KeptQuestion::accepts()}); anything else reads as nothing kept.
 *
 * Three ages, all configurable under `billing-client.kept`:
 *  - `fresh_for` (30 s): used as is, by everyone.
 *  - `max_age` (1 day): a safe request is still served a stale answer at once and a refresh is
 *    queued; past it the request waits for Billing once.
 *  - `keep_for` (7 days): how long the answer stays in the store at all, so that a Billing
 *    outage can still be served the last answer on a safe request even past `max_age`.
 */
class CacheKeptAnswers implements KeptAnswers
{
    public function __construct(
        private readonly Container $app,
        private readonly Cache $cache,
        private readonly CurrentRequest $request,
        private readonly BackgroundJobs $jobs,
        private readonly int $freshFor = 30,
        private readonly int $maxAge = 86_400,
        private readonly int $keepFor = 604_800,
        private readonly string $prefix = 'cbox-billing-client:kept:',
    ) {}

    public function answer(KeptQuestion $question, bool $acting = false): array|string|int|float|bool|null
    {
        $kept = $this->read($question);
        $servesStale = ! $acting && $this->request->safe() === true;

        if ($kept !== null && $kept->age <= $this->maxAge) {
            if ($kept->fresh) {
                return $kept->value;
            }

            if ($servesStale) {
                $this->queueRefresh($question);

                return $kept->value;
            }
        }

        try {
            return $this->refresh($question);
        } catch (BillingClientException $e) {
            // Billing is away and a page is only looking: the last answer beats an error page.
            if ($kept !== null && $servesStale) {
                return $kept->value;
            }

            throw $e;
        }
    }

    public function refresh(KeptQuestion $question): array|string|int|float|bool|null
    {
        $answer = $question->ask($this->app);
        $this->keep($question, $answer);

        return $answer;
    }

    public function read(KeptQuestion $question): ?KeptAnswer
    {
        $stored = $this->cache->get($this->key($question));

        if (! is_array($stored) || ! is_int($stored['at'] ?? null) || ! array_key_exists('value', $stored)) {
            return null;
        }

        $value = $stored['value'];

        if (! self::plain($value) || ! $question->accepts($value)) {
            return null;
        }

        /** @var array<array-key, mixed>|string|int|float|bool|null $value */
        $age = max(0, Carbon::now()->getTimestamp() - $stored['at']);

        if ($age > $this->keepFor) {
            return null;
        }

        return new KeptAnswer($value, $stored['at'], $age, $age <= $this->freshFor);
    }

    public function keep(KeptQuestion $question, array|string|int|float|bool|null $answer): void
    {
        if (! self::plain($answer) || ! $question->accepts($answer)) {
            throw new InvalidArgumentException('A kept answer must be plain data its question accepts.');
        }

        $this->cache->put($this->key($question), ['value' => $answer, 'at' => Carbon::now()->getTimestamp()], max(1, $this->keepFor));
        $this->jobs->release($this->claim($question));
    }

    public function forget(KeptQuestion $question): void
    {
        $this->cache->forget($this->key($question));
    }

    /** Queue one refresh per question; a failed refresh holds the claim, so it backs off. */
    private function queueRefresh(KeptQuestion $question): void
    {
        $this->jobs->dispatchOnce($this->claim($question), new RefreshKeptAnswer($question), RefreshKeptAnswer::UNIQUE_FOR);
    }

    private function key(KeptQuestion $question): string
    {
        return $this->prefix.hash('sha256', $question->key());
    }

    private function claim(KeptQuestion $question): string
    {
        return 'kept:'.hash('sha256', $question->key());
    }

    /** Arrays of arrays and scalars only: nothing that unserializes into an object. */
    private static function plain(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::plain($item)) {
                return false;
            }
        }

        return true;
    }
}
