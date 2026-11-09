<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Access;

use Cbox\Billing\Client\Contracts\KeptQuestion;
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use Illuminate\Contracts\Container\Container;

/** "May this person, in this organization, use this service?" as a {@see KeptQuestion}. */
readonly class ServiceAccessQuestion implements KeptQuestion
{
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

    public function accepts(mixed $value): bool
    {
        return AccessDecision::fromArray($value) !== null;
    }
}
