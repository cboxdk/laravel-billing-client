<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Enums\FeatureType;

/** A live feature snapshot. Empty snapshots and suspended accounts grant nothing. */
readonly class FeatureEntitlements extends OrganizationFeatures
{
    /** @param array<string, FeatureEntitlement> $features */
    public function __construct(public string $org = '', public array $features = [], bool $suspended = false)
    {
        $grants = [];
        foreach ($features as $key => $feature) {
            if ($feature->enabled && $feature->type !== null) {
                $value = $feature->type === FeatureType::Boolean ? true : $feature->value;
                if ($value !== null) {
                    $grants[$key] = $value;
                }
            }
        }
        parent::__construct($suspended, $grants);
    }

    public function enabled(string $key): bool
    {
        $feature = $this->for($key);

        return ! $this->suspended && $feature !== null && $feature->enabled;
    }

    public function for(string $key): ?FeatureEntitlement
    {
        return $this->features[$key] ?? null;
    }
}
