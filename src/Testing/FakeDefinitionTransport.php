<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\DefinitionTransport;
use Cbox\Billing\Client\Definitions\DefinitionManifest;
use Cbox\Billing\Client\Exceptions\TransportException;

final class FakeDefinitionTransport implements DefinitionTransport
{
    /** @var list<DefinitionManifest> */
    public array $publications = [];

    public ?TransportException $refusal = null;

    public function sync(DefinitionManifest $manifest): void
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
        $this->publications[] = $manifest;
    }
}
