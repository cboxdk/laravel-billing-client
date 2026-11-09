<?php

declare(strict_types=1);

use Cbox\Billing\Client\Access\ServiceAccess;
use Cbox\Billing\Client\Access\ServiceAccessQuestion;
use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Jobs\RefreshKeptAnswer;
use Cbox\Billing\Client\Kept\CurrentRequest;
use Cbox\Billing\Client\Testing\FakeServiceAccessTransport;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config([
        'billing-client.cache_store' => 'array',
        'billing-client.service_access.service' => 'cbox-test',
    ]);

    $this->fake = new FakeServiceAccessTransport;
    $this->app->instance(ServiceAccessTransport::class, $this->fake);
    $this->fake->allow('usr_1', 'org_1')->refuse('usr_2', 'org_1');
});

function serveRequest(string $method): void
{
    app()->instance('request', Request::create('/page', $method));
}

function access(): ServiceAccess
{
    return app(ServiceAccess::class);
}

it('asks Billing once and answers from the kept answer while it is fresh', function (): void {
    expect(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and(access()->decision('usr_2', 'org_1'))->toEqual(new AccessDecision(false, 'waitlisted'))
        ->and(access()->allows('usr_2', 'org_1'))->toBeFalse()
        ->and($this->fake->checks())->toBe(2);
});

it('serves a stale answer at once on a page and queues one refresh', function (): void {
    Bus::fake();
    access()->allows('usr_1', 'org_1');
    access()->allows('usr_2', 'org_1');
    $this->travel(31)->seconds();

    $this->fake->refuse('usr_1', 'org_1');

    expect(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and(access()->allows('usr_2', 'org_1'))->toBeFalse()
        ->and($this->fake->checks())->toBe(2);

    // One refresh per question, however many pages noticed.
    Bus::assertDispatchedTimes(RefreshKeptAnswer::class, 2);
    Bus::assertDispatched(RefreshKeptAnswer::class, fn (RefreshKeptAnswer $job): bool => $job->question == new ServiceAccessQuestion('cbox-test', 'usr_1', 'org_1')
        && $job->tries === 1);
});

it('lets the background refresh replace the stale answer', function (): void {
    access()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    $this->fake->refuse('usr_1', 'org_1', 'revoked');

    // The sync queue runs the refresh right after the page took the kept answer.
    expect(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and(access()->decision('usr_1', 'org_1'))->toEqual(new AccessDecision(false, 'revoked'))
        ->and($this->fake->checks())->toBe(2);
});

it('never acts on a stale answer in a change', function (string $method): void {
    Bus::fake();
    access()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    $this->fake->refuse('usr_1', 'org_1');
    serveRequest($method);

    expect(access()->allows('usr_1', 'org_1'))->toBeFalse()
        ->and($this->fake->checks())->toBe(2);
    Bus::assertNotDispatched(RefreshKeptAnswer::class);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('asks now when the caller is acting, but uses a fresh answer', function (): void {
    access()->allows('usr_1', 'org_1');

    expect(access()->allows('usr_1', 'org_1', acting: true))->toBeTrue()
        ->and($this->fake->checks())->toBe(1);

    $this->travel(31)->seconds();
    $this->fake->refuse('usr_1', 'org_1');

    expect(access()->allows('usr_1', 'org_1', acting: true))->toBeFalse()
        ->and($this->fake->checks())->toBe(2);
});

it('asks now outside an HTTP request', function (): void {
    access()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    $this->fake->refuse('usr_1', 'org_1');

    $this->app->instance(CurrentRequest::class, new class($this->app) extends CurrentRequest
    {
        public function safe(): ?bool
        {
            return null;
        }
    });
    $this->app->forgetInstance(KeptAnswers::class);
    $this->app->forgetInstance(ServiceAccess::class);

    expect(access()->allows('usr_1', 'org_1'))->toBeFalse()
        ->and($this->fake->checks())->toBe(2);
});

it('makes a page wait once when the answer is older than max_age', function (): void {
    Bus::fake();
    access()->allows('usr_1', 'org_1');
    $this->travel(86_401)->seconds();
    $this->fake->refuse('usr_1', 'org_1');

    expect(access()->allows('usr_1', 'org_1'))->toBeFalse()
        ->and($this->fake->checks())->toBe(2);
    Bus::assertNotDispatched(RefreshKeptAnswer::class);
});

it('serves the kept answer on a page while Billing is failing, however old', function (string $failure): void {
    Bus::fake();
    access()->allows('usr_1', 'org_1');
    access()->allows('usr_2', 'org_1');
    $this->travel(2)->days();
    $failure === 'down' ? $this->fake->takeDown() : $this->fake->refuseWith(503);

    expect(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and(access()->allows('usr_2', 'org_1'))->toBeFalse();
})->with(['down', 'unavailable']);

it('throws as before when Billing is failing and nothing is kept', function (): void {
    $this->fake->takeDown();

    expect(fn () => access()->allows('usr_1', 'org_1'))->toThrow(BillingUnavailable::class);

    $this->fake->bringUp()->refuseWith(401);

    expect(fn () => access()->allows('usr_1', 'org_1'))->toThrow(BillingRefused::class);
});

it('does not decide a change on a stale answer while Billing is failing', function (): void {
    access()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    $this->fake->takeDown();
    serveRequest('POST');

    expect(fn () => access()->allows('usr_1', 'org_1'))->toThrow(BillingUnavailable::class);
});

it('keeps the old answer and backs off when the background refresh fails', function (): void {
    $reported = [];
    $this->app->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class, function ($mock) use (&$reported): void {
        $mock->shouldReceive('report')->andReturnUsing(function (Throwable $e) use (&$reported): void {
            $reported[] = $e;
        });
    }));

    access()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();
    $this->fake->takeDown();

    // The sync queue runs the refresh: it fails, is reported, and the page still gets its answer.
    expect(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(BillingUnavailable::class)
        ->and($this->fake->checks())->toBe(2);

    // Within the backoff no new refresh is queued.
    access()->allows('usr_1', 'org_1');
    expect($this->fake->checks())->toBe(2);

    $this->travel(RefreshKeptAnswer::UNIQUE_FOR + 1)->seconds();
    $this->fake->bringUp()->refuse('usr_1', 'org_1');
    access()->allows('usr_1', 'org_1');

    expect($this->fake->checks())->toBe(3)
        ->and(access()->allows('usr_1', 'org_1'))->toBeFalse();
});

it('lets the app overwrite or drop a kept answer', function (): void {
    access()->remember('usr_3', 'org_1', true);

    expect(access()->allows('usr_3', 'org_1'))->toBeTrue()
        ->and($this->fake->checks())->toBe(0);

    access()->remember('usr_3', 'org_1', new AccessDecision(false, 'revoked'));
    expect(access()->decision('usr_3', 'org_1')->reason)->toBe('revoked');

    access()->forget('usr_3', 'org_1');
    expect(access()->decision('usr_3', 'org_1')->reason)->toBe('not_admitted')
        ->and($this->fake->checks())->toBe(1);
});

it('keeps answers apart per service, subject and organization', function (): void {
    access()->allows('usr_1', 'org_1');

    expect(access()->allows('usr_1', 'org_2'))->toBeFalse()
        ->and(access()->allows('usr_1'))->toBeFalse()
        ->and(access()->forService('cbox-other')->allows('usr_1', 'org_1'))->toBeTrue()
        ->and($this->fake->checks())->toBe(4);
});

it('keeps only plain data and treats anything else in the store as nothing kept', function (mixed $stored): void {
    $answers = app(KeptAnswers::class);
    $question = new ServiceAccessQuestion('cbox-test', 'usr_1', 'org_1');
    $answers->refresh($question);

    app('cache')->store('array')->put('cbox-billing-client:kept:'.hash('sha256', $question->key()), $stored, 60);

    expect($answers->read($question))->toBeNull()
        ->and(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and($this->fake->checks())->toBe(2);
})->with([
    'not an array' => ['yes'],
    'no time' => [['value' => ['allowed' => true, 'reason' => null]]],
    'string time' => [['value' => ['allowed' => true, 'reason' => null], 'at' => '1']],
    'not a decision' => [['value' => ['allowed' => 'yes'], 'at' => 1]],
    'an object' => [['value' => new ArrayObject(['allowed' => true]), 'at' => 1]],
]);

it('refuses to keep what is not plain data the question accepts', function (): void {
    $answers = app(KeptAnswers::class);

    expect(fn () => $answers->keep(new ServiceAccessQuestion('s', 'u'), ['allowed' => 'maybe']))
        ->toThrow(InvalidArgumentException::class);
});

it('needs a service key', function (): void {
    config(['billing-client.service_access.service' => null]);
    $this->app->forgetInstance(ServiceAccess::class);

    expect(fn () => access()->allows('usr_1', 'org_1'))->toThrow(InvalidArgumentException::class);
});

it('serves a page when the queue itself is down', function (): void {
    $reported = 0;
    $this->app->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class, function ($mock) use (&$reported): void {
        $mock->shouldReceive('report')->andReturnUsing(function () use (&$reported): void {
            $reported++;
        });
    }));
    $this->app->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function ($mock): void {
        $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    }));

    access()->allows('usr_1', 'org_1');
    $this->travel(31)->seconds();

    expect(access()->allows('usr_1', 'org_1'))->toBeTrue()
        ->and($reported)->toBe(1);
});

it('stores answers in the configured cache store', function (): void {
    access()->allows('usr_1', 'org_1');
    $question = new ServiceAccessQuestion('cbox-test', 'usr_1', 'org_1');

    /** @var Repository $store */
    $store = app('cache')->store('array');
    $stored = $store->get('cbox-billing-client:kept:'.hash('sha256', $question->key()));

    expect($stored)->toBeArray()
        ->and($stored['value'])->toBe(['allowed' => true, 'reason' => null])
        ->and($stored['at'])->toBeInt();
});
