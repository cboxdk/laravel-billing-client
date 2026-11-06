<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use DateTimeImmutable;

/** Cheap scripted receipt for tests; its status alone is never an admission decision. */
readonly class LimitIncrease
{
    public function __construct(
        public int $id = 0,
        public string $requestKey = '',
        public string $feature = '',
        public string $status = 'pending',
        public int $observedLimit = 0,
        public int $requestedLimit = 0,
        public string $reason = '',
        public ?int $approvedLimit = null,
        public ?string $decisionReason = null,
        public ?DateTimeImmutable $decidedAt = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $revokedAt = null,
        public ?string $revocationReason = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
