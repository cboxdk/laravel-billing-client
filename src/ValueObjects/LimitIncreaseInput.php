<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use InvalidArgumentException;

readonly class LimitIncreaseInput
{
    public string $requestKey;

    public function __construct(string $requestKey, public string $feature, public int $requestedLimit, public string $reason)
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $requestKey)
            || trim($feature) === '' || mb_strlen($feature) > 255 || $requestedLimit < 1 || $requestedLimit > 9007199254740991
            || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('A valid request UUID, feature, positive limit and reason are required.');
        }
        $this->requestKey = strtolower($requestKey);
    }

    /** @return array{request_key: string, feature: string, requested_limit: int, reason: string} */
    public function toArray(): array
    {
        return ['request_key' => $this->requestKey, 'feature' => $this->feature, 'requested_limit' => $this->requestedLimit, 'reason' => $this->reason];
    }
}
