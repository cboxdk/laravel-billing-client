<?php

declare(strict_types=1);

use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Exceptions\QuotaExceeded;
use Cbox\Billing\Client\Exceptions\TransportException;

it('fails closed for hard limits when billing is unreachable', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->takeDown();
    $client = $this->makeBillingClient(failurePolicy: FailurePolicy::Deny);

    // No local slice and billing is down -> an infra fault -> fail closed.
    expect(fn () => $client->reserve('org_a', 'api.calls', 5))
        ->toThrow(TransportException::class);

    expect($client->can('org_a', 'api.calls', 5))->toBeFalse();
});

it('fails open best-effort when billing is unreachable and the policy allows it', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000)->takeDown();
    $client = $this->makeBillingClient(failurePolicy: FailurePolicy::Allow);

    $reservation = $client->reserve('org_a', 'api.calls', 5);

    // Admitted without a lease; usage is still buffered for later reconciliation and
    // the local slice is never wrongly credited on commit.
    expect($reservation->backedByLease)->toBeFalse();
    $client->commit($reservation, 5);

    expect($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(5)
        ->and($client->balance('org_a', 'api.calls'))->toBe(0)
        ->and($client->can('org_a', 'api.calls', 5))->toBeTrue();
});

it('always fails closed on an exhausted allowance even under a fail-open policy', function (): void {
    // Billing is REACHABLE but the allowance is zero -> a semantic denial, not an outage.
    $this->transport()->grant('org_a', 'api.calls', 0);
    $client = $this->makeBillingClient(failurePolicy: FailurePolicy::Allow);

    expect(fn () => $client->reserve('org_a', 'api.calls', 5))
        ->toThrow(QuotaExceeded::class);

    expect($client->can('org_a', 'api.calls', 5))->toBeFalse();
});
