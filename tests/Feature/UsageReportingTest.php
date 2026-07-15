<?php

declare(strict_types=1);

it('reports cumulative usage to billing', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    $client->commit($client->reserve('org_a', 'api.calls', 10), 10);
    $client->commit($client->reserve('org_a', 'api.calls', 5), 5);

    expect($client->report())->toBe(1)
        ->and($this->transport()->reportedCumulative('org_a', 'api.calls'))->toBe(15);
});

it('self-corrects a dropped report because usage is reported cumulatively', function (): void {
    $this->transport()->grant('org_a', 'api.calls', 1_000);
    $client = $this->makeBillingClient(leaseSize: 100, refillThreshold: 0);

    // First delta lands.
    $client->commit($client->reserve('org_a', 'api.calls', 10), 10);
    $client->report();
    expect($this->transport()->reportedCumulative('org_a', 'api.calls'))->toBe(10);

    // Second delta is produced, but its report is DROPPED (billing unreachable).
    $client->commit($client->reserve('org_a', 'api.calls', 5), 5);
    $this->transport()->takeDown();
    expect($client->report())->toBe(0); // nothing reported during the outage
    expect($this->transport()->reportedCumulative('org_a', 'api.calls'))->toBe(10); // still stale

    // A third delta is produced and billing recovers. The next cumulative report
    // carries the running total (20), backfilling the dropped delta of 5.
    $client->commit($client->reserve('org_a', 'api.calls', 5), 5);
    $this->transport()->bringUp();
    $client->report();

    expect($this->usageBuffer()->cumulative('org_a', 'api.calls'))->toBe(20)
        ->and($this->transport()->reportedCumulative('org_a', 'api.calls'))->toBe(20);
});
