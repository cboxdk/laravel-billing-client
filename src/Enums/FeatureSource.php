<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

enum FeatureSource: string
{
    case Plan = 'plan';
    case Override = 'override';
    case Default = 'default';
}
