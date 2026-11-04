<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/** The URL is absent on an idempotent replay: Billing never persists bearer URLs. */
readonly class OneOffCheckoutSession
{
    public function __construct(public ?string $url = null, public ?DateTimeImmutable $expiresAt = null) {}
}
