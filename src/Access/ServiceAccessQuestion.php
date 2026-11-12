<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Access;

use Cbox\Billing\Client\Contracts\JudgesKeptAnswer;
use Cbox\Billing\Client\Contracts\OrganizationQuestion;
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use Illuminate\Contracts\Container\Container;

/**
 * "May this person, in this organization, use this service?" as a kept question.
 *
 * A "yes" is kept like any answer: served to a page however old, refreshed in the background.
 * A "no" is not: it is reused for {@see REFUSAL_FRESH_FOR} seconds and then asked again in the
 * request, so that somebody Billing has just let in is in on their next page. Nobody refused is
 * inside the app, so that wait costs no working page. While Billing fails, a page is still
 * served the kept refusal.
 */
readonly class ServiceAccessQuestion implements JudgesKeptAnswer, OrganizationQuestion
{
    /** Seconds a refusal is reused before it is asked again. */
    public const int REFUSAL_FRESH_FOR = 5;

    public function __construct(
        public string $service,
        public string $subject,
        public ?string $organization = null,
    ) {}

    public function key(): string
    {
        return 'access:'.json_encode([$this->service, $this->subject, $this->organization], JSON_THROW_ON_ERROR);
    }

    /** @return array{allowed: bool, reason: ?string} */
    public function ask(Container $app): array
    {
        return $app->make(ServiceAccessTransport::class)
            ->check($this->service, $this->subject, $this->organization)
            ->toArray();
    }

    public function organization(): ?string
    {
        return $this->organization;
    }

    public function freshFor(mixed $value): ?int
    {
        return self::allowed($value) ? null : self::REFUSAL_FRESH_FOR;
    }

    public function servesStale(mixed $value): bool
    {
        return self::allowed($value);
    }

    private static function allowed(mixed $value): bool
    {
        return AccessDecision::fromArray($value)?->allowed === true;
    }

    public function accepts(mixed $value): bool
    {
        return AccessDecision::fromArray($value) !== null;
    }
}
