<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

use Cbox\Billing\Client\Enums\FeatureSource;
use Cbox\Billing\Client\Enums\FeatureType;

/** A cheap, deny-by-default feature decision; an empty instance grants nothing. */
readonly class FeatureEntitlement
{
    public function __construct(public string $key = '', public ?FeatureType $type = null, public bool $enabled = false, public int|string|null $value = null, public FeatureSource $source = FeatureSource::Default) {}

    /** @return array{type: string|null, enabled: bool, value: int|string|null, source: string} */
    public function toArray(): array
    {
        return ['type' => $this->type?->value, 'enabled' => $this->enabled, 'value' => $this->value, 'source' => $this->source->value];
    }
}
