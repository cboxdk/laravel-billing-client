<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Enums;

enum FeatureType: string
{
    case Boolean = 'boolean';
    case Config = 'config';
}
