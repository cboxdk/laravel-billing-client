<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\SponsoredCapacityTransport;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\CapacityAllocation;
use Cbox\Billing\Client\ValueObjects\CapacityClaim;
use LogicException;

/**
 * Script outcomes explicitly, including ambiguous failures and terminal replays. This is
 * not a second ledger implementation and never grants unconfigured capacity by default.
 */
class FakeSponsoredCapacityTransport implements SponsoredCapacityTransport
{
    /** @var list<CapacityClaim|CapacityAllocation|TransportException> */
    public array $responses = [];

    /** @var list<array{operation: string, arguments: list<string|int>}> */
    public array $calls = [];

    public function reserve(string $agreement, string $claim, string $org, string $allocation, int $amount): CapacityClaim
    {
        return $this->nextClaim('reserve', [$agreement, $claim, $org, $allocation, $amount]);
    }

    public function claim(string $agreement, string $claim): CapacityClaim
    {
        return $this->nextClaim('claim', [$agreement, $claim]);
    }

    public function commit(string $agreement, string $claim, int $actual): CapacityClaim
    {
        return $this->nextClaim('commit', [$agreement, $claim, $actual]);
    }

    public function cancel(string $agreement, string $claim): CapacityClaim
    {
        return $this->nextClaim('cancel', [$agreement, $claim]);
    }

    public function allocation(string $agreement, string $allocation): CapacityAllocation
    {
        return $this->nextAllocation('allocation', [$agreement, $allocation]);
    }

    public function release(string $agreement, string $allocation, string $deletion): CapacityAllocation
    {
        return $this->nextAllocation('release', [$agreement, $allocation, $deletion]);
    }

    /** @param list<string|int> $arguments */
    private function nextClaim(string $operation, array $arguments): CapacityClaim
    {
        $result = $this->next($operation, $arguments);

        return $result instanceof CapacityClaim ? $result : throw new LogicException('Queue a CapacityClaim for '.$operation.'.');
    }

    /** @param list<string|int> $arguments */
    private function nextAllocation(string $operation, array $arguments): CapacityAllocation
    {
        $result = $this->next($operation, $arguments);

        return $result instanceof CapacityAllocation ? $result : throw new LogicException('Queue a CapacityAllocation for '.$operation.'.');
    }

    /** @param list<string|int> $arguments */
    private function next(string $operation, array $arguments): CapacityClaim|CapacityAllocation
    {
        $this->calls[] = compact('operation', 'arguments');
        $result = array_shift($this->responses) ?? new BillingUnavailable('No sponsored capacity response configured.');
        if ($result instanceof TransportException) {
            throw $result;
        }

        return $result;
    }
}
