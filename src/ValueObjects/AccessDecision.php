<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Access\ServiceAccess;

/**
 * Billing's answer to "may this person (in this organization) use the service?"
 * (`POST /v1/access/services/{service}/check`): `allowed`, and Billing's `reason` code
 * when it says no (e.g. `waitlisted`). {@see ServiceAccess} keeps it as {@see toArray()}.
 * `new AccessDecision` is a valid instance: a refusal with no reason.
 */
readonly class AccessDecision
{
    public function __construct(
        public bool $allowed = false,
        public ?string $reason = null,
    ) {}

    /** @return array{allowed: bool, reason: ?string} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason];
    }

    /** The decision a kept or wire array holds, or null when it is not one. */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! is_bool($value['allowed'] ?? null)) {
            return null;
        }

        $reason = $value['reason'] ?? null;

        if ($reason !== null && ! is_string($reason)) {
            return null;
        }

        return new self($value['allowed'], $reason === '' ? null : $reason);
    }
}
