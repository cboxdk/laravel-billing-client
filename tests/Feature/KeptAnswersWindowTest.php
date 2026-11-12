<?php

declare(strict_types=1);

use Cbox\Billing\Client\Access\ServiceAccess;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Jobs\RefreshKeptAnswer;
use Cbox\Billing\Client\Kept\KeptChanges;
use Cbox\Billing\Client\Kept\KeptSubscriptions;
use Cbox\Billing\Client\Testing\FakeServiceAccessTransport;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config([
        'billing-client.cache_store' => 'array',
        'billing-client.service_access.service' => 'cbox-test',
    ]);

    $this->fake = new FakeServiceAccessTransport;
    $this->app->instance(ServiceAccessTransport::class, $this->fake);
    $this->app->instance(ManagementTransport::class, $this->managementTransport());
    $this->fake->allow('usr_1', 'org_1')->allow('usr_1', 'org_2')->refuse('usr_2', 'org_1');
});

/** A new HTTP request, as the next page view is. */
function nextRequest(string $method = 'GET'): void
{
    app()->instance('request', Request::create('/page', $method));
}

function admission(): ServiceAccess
{
    return app(ServiceAccess::class);
}

function changes(): KeptChanges
{
    return app(KeptChanges::class);
}

it('serves a returning person the kept yes weeks later, and asks again in the background', function (): void {
    Bus::fake();
    admission()->allows('usr_1', 'org_1');
    $this->travel(21)->days();
    $this->fake->takeDown();
    nextRequest();

    expect(admission()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and($this->fake->checks())->toBe(1);
    Bus::assertDispatchedTimes(RefreshKeptAnswer::class, 1);
});

it('lets a returning person in while Billing is down, past the window', function (): void {
    Bus::fake();
    admission()->allows('usr_1', 'org_1');
    $this->travel(45)->days();
    $this->fake->takeDown();
    nextRequest();

    expect(admission()->allows('usr_1', 'org_1'))->toBeTrue();
});

it('reads the store once however many times one request asks', function (): void {
    admission()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    Bus::fake();
    nextRequest();

    $reads = 0;
    Event::listen([CacheHit::class, CacheMissed::class], function (CacheHit|CacheMissed $event) use (&$reads): void {
        if (str_starts_with($event->key, 'cbox-billing-client:kept:')) {
            $reads++;
        }
    });

    foreach (range(1, 9) as $ask) {
        expect(admission()->allows('usr_1', 'org_1'))->toBeTrue();
    }

    expect($reads)->toBe(1)
        ->and($this->fake->checks())->toBe(1);
    Bus::assertDispatchedTimes(RefreshKeptAnswer::class, 1);

    // The next request reads again.
    nextRequest();
    admission()->allows('usr_1', 'org_1');
    expect($reads)->toBe(2);
});

it('asks Billing once when nine asks in one first visit find nothing kept', function (): void {
    nextRequest();

    foreach (range(1, 9) as $ask) {
        expect(admission()->allows('usr_1', 'org_1'))->toBeTrue();
    }

    expect($this->fake->checks())->toBe(1);
});

it('sees within the request what the app itself remembered or dropped', function (): void {
    nextRequest();
    expect(admission()->allows('usr_2', 'org_1'))->toBeFalse();

    admission()->remember('usr_2', 'org_1', true);
    expect(admission()->allows('usr_2', 'org_1'))->toBeTrue();

    admission()->forget('usr_2', 'org_1');
    expect(admission()->allows('usr_2', 'org_1'))->toBeFalse()
        ->and($this->fake->checks())->toBe(2);
});

it('still asks now for a change in a request that already read a stale answer', function (): void {
    Bus::fake();
    admission()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    $this->fake->refuse('usr_1', 'org_1', 'revoked');
    nextRequest();

    expect(admission()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and(admission()->allows('usr_1', 'org_1', acting: true))->toBeFalse()
        ->and(admission()->allows('usr_1', 'org_1'))->toBeFalse();
});

it('does not serve a kept yes once Billing said the organization changed', function (): void {
    Bus::fake();
    admission()->allows('usr_1', 'org_1');
    admission()->allows('usr_1', 'org_2');
    $this->travel(5)->seconds();
    $this->fake->refuse('usr_1', 'org_1', 'revoked')->refuse('usr_1', 'org_2', 'revoked');

    changes()->organization('org_1');
    nextRequest();

    // org_1 is asked now, on a page too; org_2 was not named and keeps its answer.
    expect(admission()->decision('usr_1', 'org_1'))->toEqual(new AccessDecision(false, 'revoked'))
        ->and(admission()->allows('usr_1', 'org_2'))->toBeTrue()
        ->and($this->fake->checks())->toBe(3);
    Bus::assertNotDispatched(RefreshKeptAnswer::class);
});

it('uses an answer asked after the change as usual', function (): void {
    admission()->allows('usr_1', 'org_1');
    changes()->organization('org_1');
    $this->travel(1)->seconds();
    nextRequest();
    admission()->allows('usr_1', 'org_1');
    nextRequest();
    admission()->allows('usr_1', 'org_1');

    expect($this->fake->checks())->toBe(2);
});

it('serves the kept answer on a page when the organization changed and Billing is down', function (): void {
    admission()->allows('usr_1', 'org_1');
    changes()->organization('org_1');
    $this->fake->takeDown();
    nextRequest();

    expect(admission()->allows('usr_1', 'org_1'))->toBeTrue();

    nextRequest('POST');
    expect(fn () => admission()->allows('usr_1', 'org_1'))->toThrow(BillingUnavailable::class);
});

it('marks every kept answer changed when Billing says a plan changed', function (): void {
    admission()->allows('usr_1', 'org_1');
    admission()->allows('usr_1', 'org_2');
    $this->fake->refuse('usr_1', 'org_1')->refuse('usr_1', 'org_2');

    changes()->everything();
    nextRequest();

    expect(admission()->allows('usr_1', 'org_1'))->toBeFalse()
        ->and(admission()->allows('usr_1', 'org_2'))->toBeFalse();
});

it('reads a Billing webhook: which organization changed, or everything', function (string $type, array $payload, bool $marked, bool $org1AskedAgain, bool $org2AskedAgain): void {
    admission()->allows('usr_1', 'org_1');
    admission()->allows('usr_1', 'org_2');

    expect(changes()->fromWebhook($type, $payload))->toBe($marked);
    nextRequest();
    admission()->allows('usr_1', 'org_1');
    admission()->allows('usr_1', 'org_2');

    expect($this->fake->checks())->toBe(2 + (int) $org1AskedAgain + (int) $org2AskedAgain);
})->with([
    'a canceled subscription' => ['subscription.canceled', ['id' => 'sub_1', 'organization_ref' => 'org_1'], true, true, false],
    'a suspended customer' => ['customer.suspended', ['organization_ref' => 'org_2', 'suspended' => true], true, false, true],
    'a redeemed invitation' => ['access.redeemed', ['organization_ref' => 'org_1'], true, true, false],
    'a plan change' => ['plan.changed', ['plan' => 'pro', 'product' => 'cbox-test'], true, true, true],
    'an invoice' => ['invoice.issued', ['number' => 'INV-1', 'organization_ref' => 'org_1'], false, false, false],
    'a subscription event without an organization' => ['subscription.changed', ['id' => 'sub_1'], false, false, false],
]);

it('reads the subscription again after the organization changed', function (): void {
    $this->managementTransport()->withSubscription('org_1', new Subscription('pro', 'active'));
    app(KeptSubscriptions::class)->subscription('org_1');
    $this->managementTransport()->withSubscription('org_1', new Subscription('team', 'active'));

    changes()->fromWebhook('subscription.changed', ['organization_ref' => 'org_1']);
    nextRequest();

    expect(app(KeptSubscriptions::class)->subscription('org_1')?->plan)->toBe('team');
});

it('asks again about a refusal a page meets once it is a few seconds old', function (): void {
    Bus::fake();
    nextRequest();
    expect(admission()->allows('usr_2', 'org_1'))->toBeFalse();

    // Billing let them in meanwhile (an invitation, an operator): the next page must see it.
    $this->fake->allow('usr_2', 'org_1');
    $this->travel(6)->seconds();
    nextRequest();

    expect(admission()->allows('usr_2', 'org_1'))->toBeTrue()
        ->and($this->fake->checks())->toBe(2);
    Bus::assertNotDispatched(RefreshKeptAnswer::class);
});

it('reuses a refusal for a few seconds, and serves it while Billing is down', function (): void {
    admission()->allows('usr_2', 'org_1');
    $this->travel(3)->seconds();
    nextRequest();
    admission()->allows('usr_2', 'org_1');
    expect($this->fake->checks())->toBe(1);

    $this->travel(1)->hours();
    $this->fake->takeDown();
    nextRequest();

    expect(admission()->decision('usr_2', 'org_1'))->toEqual(new AccessDecision(false, 'waitlisted'));
});

it('refreshes after the response when the app runs no queue', function (): void {
    config(['queue.default' => 'sync']);
    admission()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    $this->fake->refuse('usr_1', 'org_1', 'revoked');
    nextRequest();

    // The page is answered from the kept yes without a call to Billing ...
    expect(admission()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and($this->fake->checks())->toBe(1);

    // ... and Billing is asked once the response is out.
    $this->app->terminate();
    expect($this->fake->checks())->toBe(2);

    nextRequest();
    expect(admission()->allows('usr_1', 'org_1'))->toBeFalse();
});
