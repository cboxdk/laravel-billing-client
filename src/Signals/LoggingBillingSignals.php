<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Signals;

use Cbox\Billing\Client\Contracts\BillingSignals;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A {@see BillingSignals} that logs each enforcement decision and background action at
 * a low level, for hosts that want visibility without wiring a metrics backend. It
 * never lets a logging failure escape into the hot path — a broken log sink must not
 * break enforcement.
 */
class LoggingBillingSignals implements BillingSignals
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level = 'debug',
    ) {}

    public function allowed(string $org, string $meter, int $amount, bool $backedByLease): void
    {
        $this->log('billing.allowed', [
            'org' => $org,
            'meter' => $meter,
            'amount' => $amount,
            'backed_by_lease' => $backedByLease,
        ]);
    }

    public function denied(string $org, string $meter, int $amount, string $reason): void
    {
        $this->log('billing.denied', [
            'org' => $org,
            'meter' => $meter,
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }

    public function refilled(string $org, string $meter, int $granted): void
    {
        $this->log('billing.refilled', [
            'org' => $org,
            'meter' => $meter,
            'granted' => $granted,
        ]);
    }

    public function reported(int $organizations): void
    {
        $this->log('billing.reported', ['organizations' => $organizations]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $message, array $context): void
    {
        try {
            $this->logger->log($this->level, $message, $context);
        } catch (Throwable) {
            // An observer must never break enforcement.
        }
    }
}
