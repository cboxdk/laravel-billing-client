<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\Contracts\JudgesKeptAnswer;
use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Contracts\KeptQuestion;
use Cbox\Billing\Client\Contracts\OrganizationQuestion;
use Cbox\Billing\Client\Exceptions\BillingClientException;
use Cbox\Billing\Client\Jobs\RefreshKeptAnswer;
use Cbox\Billing\Client\Support\BackgroundJobs;
use Cbox\Billing\Client\ValueObjects\KeptAnswer;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use WeakMap;

/**
 * {@see KeptAnswers} in the cache store. Each answer is stored as `{value, at}`: plain data and
 * the Unix time Billing gave it. What comes back is validated before it is used (shape, time,
 * and the question's own {@see KeptQuestion::accepts()}); anything else reads as nothing kept.
 *
 * Three ages, all configurable under `billing-client.kept`:
 *  - `fresh_for` (30 s): used as is, by everyone.
 *  - `max_age` (30 days): a safe request is still served a stale answer at once and a refresh
 *    is queued; past it the request waits for Billing once.
 *  - `keep_for` (60 days): how long the answer stays in the store at all, so that a Billing
 *    outage can still be served the last answer on a safe request even past `max_age`.
 *
 * Within one HTTP request a question is read from the store once and Billing is asked at most
 * once, however many times the request asks: what was read, a refresh already queued and a
 * failure already met are remembered against the request object and go with it.
 *
 * An answer about an organization that Billing has since said changed ({@see KeptChanges}) is
 * asked again instead of served.
 */
class CacheKeptAnswers implements KeptAnswers
{
    /**
     * What each request has read, keyed by the request object.
     *
     * @var WeakMap<object, array<string, array{stored: array{value: array<array-key, mixed>|string|int|float|bool|null, at: int}|null, changed: int, generation: int, queued: bool, failure: BillingClientException|null}>>
     */
    private WeakMap $held;

    public function __construct(
        private readonly Container $app,
        private readonly Cache $cache,
        private readonly CurrentRequest $request,
        private readonly BackgroundJobs $jobs,
        private readonly int $freshFor = 30,
        private readonly int $maxAge = 2_592_000,
        private readonly int $keepFor = 5_184_000,
        private readonly string $prefix = 'cbox-billing-client:kept:',
        private readonly ?KeptChanges $changes = null,
    ) {
        $this->held = new WeakMap;
    }

    public function answer(KeptQuestion $question, bool $acting = false): array|string|int|float|bool|null
    {
        $held = $this->held($question);
        $kept = $this->keptAnswer($question, $held['stored']);
        $servesStale = ! $acting && $this->request->safe() === true;
        $current = $kept !== null && $kept->askedAt > $held['changed'];

        if ($kept !== null && $current && $kept->age <= $this->maxAge) {
            if ($kept->fresh) {
                return $kept->value;
            }

            if ($servesStale && (! $question instanceof JudgesKeptAnswer || $question->servesStale($kept->value))) {
                if (! $held['queued']) {
                    $this->queueRefresh($question);
                    $this->hold($question, ['queued' => true] + $held);
                }

                return $kept->value;
            }
        }

        try {
            // A request that already found Billing failing on this question does not try again.
            if ($held['failure'] !== null) {
                throw $held['failure'];
            }

            return $this->refresh($question);
        } catch (BillingClientException $e) {
            $this->hold($question, ['failure' => $e] + $this->held($question));

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
        return $this->keptAnswer($question, $this->held($question)['stored']);
    }

    public function keep(KeptQuestion $question, array|string|int|float|bool|null $answer): void
    {
        if (! self::plain($answer) || ! $question->accepts($answer)) {
            throw new InvalidArgumentException('A kept answer must be plain data its question accepts.');
        }

        $stored = ['value' => $answer, 'at' => Carbon::now()->getTimestamp()];
        $this->cache->put($this->key($question), $stored, max(1, $this->keepFor));
        $this->jobs->release($this->claim($question));
        $this->hold($question, ['stored' => $stored, 'queued' => false, 'failure' => null] + $this->held($question, read: false));
    }

    public function forget(KeptQuestion $question): void
    {
        $this->cache->forget($this->key($question));
        $this->hold($question, ['stored' => null, 'queued' => false, 'failure' => null] + $this->held($question, read: false));
    }

    /**
     * What is stored for `$question` and when its organization last changed: from this request's
     * memory when it has it, else from the store in one read.
     *
     * @return array{stored: array{value: array<array-key, mixed>|string|int|float|bool|null, at: int}|null, changed: int, generation: int, queued: bool, failure: BillingClientException|null}
     */
    private function held(KeptQuestion $question, bool $read = true): array
    {
        $request = $this->request->instance();
        $generation = $this->changes?->generation() ?? 0;
        $held = $request === null ? null : ($this->held[$request][$question->key()] ?? null);

        if ($held !== null && $held['generation'] === $generation) {
            return $held;
        }

        if (! $read) {
            return ['stored' => null, 'changed' => $held['changed'] ?? 0, 'generation' => $generation, 'queued' => false, 'failure' => null];
        }

        $marks = $this->changes?->keys($question instanceof OrganizationQuestion ? $question->organization() : null) ?? [];
        $key = $this->key($question);
        $values = [];
        foreach ($this->cache->getMultiple([$key, ...$marks]) as $name => $value) {
            $values[(string) $name] = $value;
        }

        $changed = 0;
        foreach ($marks as $mark) {
            $at = $values[$mark] ?? null;
            $changed = is_int($at) ? max($changed, $at) : $changed;
        }

        $held = ['stored' => $this->stored($question, $values[$key] ?? null), 'changed' => $changed, 'generation' => $generation, 'queued' => false, 'failure' => null];
        $this->hold($question, $held);

        return $held;
    }

    /**
     * Remember `$held` for the rest of this request; outside a request nothing is remembered.
     *
     * @param  array{stored: array{value: array<array-key, mixed>|string|int|float|bool|null, at: int}|null, changed: int, generation: int, queued: bool, failure: BillingClientException|null}  $held
     */
    private function hold(KeptQuestion $question, array $held): void
    {
        $request = $this->request->instance();

        if ($request === null) {
            return;
        }

        $all = $this->held[$request] ?? [];
        $all[$question->key()] = $held;
        $this->held[$request] = $all;
    }

    /**
     * The stored entry when it is a valid answer to `$question`, else null.
     *
     * @return array{value: array<array-key, mixed>|string|int|float|bool|null, at: int}|null
     */
    private function stored(KeptQuestion $question, mixed $stored): ?array
    {
        if (! is_array($stored) || ! is_int($stored['at'] ?? null) || ! array_key_exists('value', $stored)) {
            return null;
        }

        $value = $stored['value'];

        if (! self::plain($value) || ! $question->accepts($value)) {
            return null;
        }

        /** @var array<array-key, mixed>|string|int|float|bool|null $value */
        return ['value' => $value, 'at' => $stored['at']];
    }

    /**
     * The stored entry as an answer with its age now, or null when nothing is kept (any more).
     *
     * @param  array{value: array<array-key, mixed>|string|int|float|bool|null, at: int}|null  $stored
     */
    private function keptAnswer(KeptQuestion $question, ?array $stored): ?KeptAnswer
    {
        if ($stored === null) {
            return null;
        }

        $age = max(0, Carbon::now()->getTimestamp() - $stored['at']);

        if ($age > $this->keepFor) {
            return null;
        }

        $freshFor = $question instanceof JudgesKeptAnswer ? ($question->freshFor($stored['value']) ?? $this->freshFor) : $this->freshFor;

        return new KeptAnswer($stored['value'], $stored['at'], $age, $age <= $freshFor);
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
