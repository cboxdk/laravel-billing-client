<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Contracts\KeptAnswers;

/**
 * An answer Billing gave and the app kept ({@see KeptAnswers}): the plain value, the Unix time
 * it was asked, and how old it is now. `fresh` is true while it is younger than
 * `kept.fresh_for`. `new KeptAnswer` is a valid instance (an empty answer asked at the epoch).
 */
readonly class KeptAnswer
{
    /**
     * @param  array<array-key, mixed>|string|int|float|bool|null  $value
     */
    public function __construct(
        public array|string|int|float|bool|null $value = null,
        public int $askedAt = 0,
        public int $age = 0,
        public bool $fresh = false,
    ) {}
}
