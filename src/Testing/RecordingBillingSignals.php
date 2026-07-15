<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\BillingSignals;

/**
 * A {@see BillingSignals} that records every signal in memory so a test can assert the
 * enforcement hot path emitted the observability it should — allowed/denied decisions,
 * refills, and reports. Ships as a testing fake so a host can dogfood the same hook it
 * would wire to metrics in production.
 */
class RecordingBillingSignals implements BillingSignals
{
    /** @var list<array{org: string, meter: string, amount: int, backed_by_lease: bool}> */
    public array $allowed = [];

    /** @var list<array{org: string, meter: string, amount: int, reason: string}> */
    public array $denied = [];

    /** @var list<array{org: string, meter: string, granted: int}> */
    public array $refilled = [];

    /** @var list<int> */
    public array $reported = [];

    public function allowed(string $org, string $meter, int $amount, bool $backedByLease): void
    {
        $this->allowed[] = ['org' => $org, 'meter' => $meter, 'amount' => $amount, 'backed_by_lease' => $backedByLease];
    }

    public function denied(string $org, string $meter, int $amount, string $reason): void
    {
        $this->denied[] = ['org' => $org, 'meter' => $meter, 'amount' => $amount, 'reason' => $reason];
    }

    public function refilled(string $org, string $meter, int $granted): void
    {
        $this->refilled[] = ['org' => $org, 'meter' => $meter, 'granted' => $granted];
    }

    public function reported(int $organizations): void
    {
        $this->reported[] = $organizations;
    }
}
