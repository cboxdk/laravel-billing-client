<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Signals;

use Cbox\Billing\Client\Contracts\BillingSignals;

/**
 * The default {@see BillingSignals}: discards every signal. Enforcement stays silent
 * until a host binds a real observer, so the package imposes no logging or metrics
 * runtime on its consumers.
 */
class NullBillingSignals implements BillingSignals
{
    public function allowed(string $org, string $meter, int $amount, bool $backedByLease): void {}

    public function denied(string $org, string $meter, int $amount, string $reason): void {}

    public function refilled(string $org, string $meter, int $granted): void {}

    public function reported(int $organizations): void {}
}
