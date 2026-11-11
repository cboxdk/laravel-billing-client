<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\DefinitionTransport;
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Definitions\DefinitionManifest;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpServiceAccessTransport;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'billing-client.base_url' => 'https://billing.test',
        'billing-client.api_token' => 'api-token',
        'billing-client.definition_token' => 'definition-token',
        'billing-client.cache_store' => 'array',
        'billing-client.connect_timeout' => 1,
        'billing-client.timeout' => 7,
        'billing-client.lease_timeout' => 2,
    ]);
});

it('sets a connect timeout on every call it makes', function (): void {
    $seen = [];
    app(Factory::class)->globalMiddleware(function (callable $handler) use (&$seen): callable {
        return function ($request, array $options) use ($handler, &$seen) {
            $seen[parse_url((string) $request->getUri(), PHP_URL_PATH)] = [$options['connect_timeout'] ?? null, $options['timeout'] ?? null];

            return $handler($request, $options);
        };
    });
    Http::fake([
        '*/api/v1/leases' => Http::response(['lease_id' => 'l1', 'granted' => 100, 'expires_at' => null]),
        '*/api/v1/entitlements/*' => Http::response(['meters' => []]),
        '*/api/v1/subscriptions/*' => Http::response(['plan' => 'pro', 'status' => 'active']),
        '*/api/v1/access/services/*' => Http::response(['allowed' => true, 'reason' => null]),
        '*/api/v1/definitions' => Http::response(['data' => []]),
    ]);

    config(['billing-client.refill' => 'sync']);
    app(BillingClient::class)->reserve('org_1', 'api.calls', 1);
    app(BillingManagement::class)->subscription('org_1');
    app(ServiceAccessTransport::class)->check('cbox-test', 'usr_1', 'org_1');

    try {
        app(DefinitionTransport::class)->sync(DefinitionManifest::fromJson('{"version":"1","features":[],"meters":[]}'));
    } catch (Throwable) {
        // The empty manifest's answer is not the point; the call it made is.
    }

    expect($seen)->toHaveKey('/api/v1/leases')
        ->and($seen['/api/v1/leases'])->toBe([1, 2])
        ->and($seen['/api/v1/subscriptions/org_1'])->toBe([1, 7])
        ->and($seen['/api/v1/access/services/cbox-test/check'])->toBe([1, 7])
        ->and($seen['/api/v1/definitions'])->toBe([1, 10]);
});

it('asks the service access check with the subject and organization', function (): void {
    Http::fake(['*' => Http::response(['allowed' => false, 'reason' => 'waitlisted', 'program' => ['id' => 'acp_2mN8bV4cX6zL1kJ9hG3fD5']])]);

    $decision = app(ServiceAccessTransport::class)->check('cbox telemetry', 'usr_1', 'org_1');

    expect($decision)->toEqual(new AccessDecision(false, 'waitlisted'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/access/services/cbox%20telemetry/check'
        && $request->method() === 'POST'
        && $request->data() === ['subject' => 'usr_1', 'organization' => 'org_1']
        && $request->hasHeader('Authorization', 'Bearer api-token'));
});

it('uses the access URL and token when they are set', function (): void {
    config(['billing-client.service_access.url' => 'https://access.test', 'billing-client.service_access.token' => 'access-token']);
    Http::fake(['*' => Http::response(['allowed' => true])]);

    app(ServiceAccessTransport::class)->check('svc', 'usr_1');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://access.test/')
        && $request->data() === ['subject' => 'usr_1']
        && $request->hasHeader('Authorization', 'Bearer access-token'));
});

it('classifies the check failures like every other call', function (int $status, string $exception): void {
    Http::fake(['*' => Http::response(['error' => 'no'], $status)]);

    expect(fn () => app(ServiceAccessTransport::class)->check('svc', 'usr_1'))->toThrow($exception);
})->with([
    'revoked token' => [401, BillingRefused::class],
    'unknown service' => [404, BillingRefused::class],
    'outage' => [503, BillingUnavailable::class],
    'rate limited' => [429, BillingUnavailable::class],
]);

it('treats an answer without a boolean allowed as malformed', function (mixed $body): void {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => app(ServiceAccessTransport::class)->check('svc', 'usr_1'))->toThrow(BillingUnavailable::class);
})->with([
    'missing' => [['reason' => null]],
    'string' => [['allowed' => 'true']],
    'reason not a string' => [['allowed' => true, 'reason' => 5]],
]);

it('refuses an unsafe or unconfigured target before calling', function (string $url, string $token): void {
    Http::fake();

    $transport = new HttpServiceAccessTransport(app(Factory::class), $url, $token);

    expect(fn () => $transport->check('svc', 'usr_1'))->toThrow(BillingRefused::class);
    Http::assertNothingSent();
})->with([
    'no url' => ['', 'token'],
    'no token' => ['https://billing.test', ''],
    'plain http' => ['http://billing.test', 'token'],
    'credentials' => ['https://user:pass@billing.test', 'token'],
    'query' => ['https://billing.test?x=1', 'token'],
]);

it('allows plain http to localhost for development', function (): void {
    Http::fake(['*' => Http::response(['allowed' => true])]);

    expect((new HttpServiceAccessTransport(app(Factory::class), 'http://localhost:8000', 'token'))->check('svc', 'usr_1')->allowed)->toBeTrue();
});
