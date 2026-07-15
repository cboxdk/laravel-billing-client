<?php

declare(strict_types=1);

use Cbox\Billing\Client\Enums\ReserveOutcome;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\MeterActual;
use Cbox\Billing\Client\ValueObjects\MeterEstimate;

it('reserves and commits authoritatively against billing for entitled meters', function (): void {
    $this->transport()->entitlement('org_a', new Entitlement('api.calls', enabled: true, allowance: 100, weight: 1.0, overage: 'bill'));
    $client = $this->makeBillingClient();

    $decision = $client->reserveRemote('org_a', [new MeterEstimate('api.calls', 5)]);

    expect($decision->outcome)->toBe(ReserveOutcome::Allowed)
        ->and($decision->reservationId)->not->toBeNull();

    $client->commitRemote((string) $decision->reservationId, [new MeterActual('api.calls', 5)]);

    expect($this->transport()->reportCalls())->toBe(0);
});

it('denies an authoritative reservation for an unentitled meter (deny-by-default)', function (): void {
    $client = $this->makeBillingClient();

    $decision = $client->reserveRemote('org_a', [new MeterEstimate('unknown.meter', 5)]);

    expect($decision->outcome)->toBe(ReserveOutcome::Denied)
        ->and($decision->allowed())->toBeFalse();
});

it('reads an organization entitlement set with deny-by-default for unknown meters', function (): void {
    $this->transport()->entitlement('org_a', new Entitlement('api.calls', enabled: true, allowance: 100, weight: 2.0, overage: 'block'));
    $client = $this->makeBillingClient();

    $entitlements = $client->entitlements('org_a');

    expect($entitlements->enabled('api.calls'))->toBeTrue()
        ->and($entitlements->for('api.calls')?->blocksOnOverage())->toBeTrue()
        ->and($entitlements->enabled('unknown.meter'))->toBeFalse()
        ->and($entitlements->for('unknown.meter'))->toBeNull();
});
