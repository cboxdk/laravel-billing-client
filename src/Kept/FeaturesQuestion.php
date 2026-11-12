<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\FeatureTransport;
use Cbox\Billing\Client\Contracts\OrganizationQuestion;
use Cbox\Billing\Client\Enums\FeatureSource;
use Cbox\Billing\Client\Enums\FeatureType;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlement;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlements;
use Illuminate\Contracts\Container\Container;

/**
 * "Which features does this organization have?" as a kept question. The answer is the wire
 * shape of `GET /v1/entitlements/{org}/features`:
 * `['suspended' => bool, 'features' => [key => {type, enabled, value, source}]]`.
 */
readonly class FeaturesQuestion implements OrganizationQuestion
{
    public function __construct(public string $organization) {}

    public function key(): string
    {
        return 'features:'.$this->organization;
    }

    public function organization(): string
    {
        return $this->organization;
    }

    /** @return array{suspended: bool, features: array<string, array{type: string|null, enabled: bool, value: int|string|null, source: string}>} */
    public function ask(Container $app): array
    {
        $transport = $app->make(BillingTransport::class);

        if (! $transport instanceof FeatureTransport) {
            throw new BillingUnavailable('The billing transport does not support feature entitlements.');
        }

        return self::wrap($transport->features($this->organization));
    }

    public function accepts(mixed $value): bool
    {
        return self::parse($this->organization, $value) instanceof FeatureEntitlements;
    }

    /** @return array{suspended: bool, features: array<string, array{type: string|null, enabled: bool, value: int|string|null, source: string}>} */
    public static function wrap(FeatureEntitlements $features): array
    {
        $rows = [];
        foreach ($features->features as $key => $feature) {
            $rows[$key] = $feature->toArray();
        }

        return ['suspended' => $features->suspended, 'features' => $rows];
    }

    /** The features a kept answer holds, or null when it is not a valid answer. */
    public static function parse(string $organization, mixed $value): ?FeatureEntitlements
    {
        if (! is_array($value) || ! is_bool($value['suspended'] ?? null) || ! is_array($value['features'] ?? null)) {
            return null;
        }

        $features = [];
        foreach ($value['features'] as $key => $row) {
            if (! is_string($key) || $key === '' || ! is_array($row) || ! is_bool($row['enabled'] ?? null) || ! array_key_exists('value', $row)) {
                return null;
            }

            $type = is_string($row['type'] ?? null) ? FeatureType::tryFrom($row['type']) : null;
            $source = is_string($row['source'] ?? null) ? FeatureSource::tryFrom($row['source']) : null;
            $held = $row['value'];

            if ($type === null || $source === null || (! is_int($held) && ! is_string($held) && $held !== null)) {
                return null;
            }

            $features[$key] = new FeatureEntitlement($key, $type, $row['enabled'], $held, $source);
        }

        return new FeatureEntitlements($organization, $features, $value['suspended']);
    }
}
