<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Definitions\DefinitionManifest;

interface DefinitionTransport
{
    /** Publish all definitions; return only after Billing acknowledges every declared key. */
    public function sync(DefinitionManifest $manifest): void;
}
