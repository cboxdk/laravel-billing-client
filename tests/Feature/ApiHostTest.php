<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\DefinitionTransport;
use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Definitions\DefinitionManifest;
use Cbox\Billing\Client\Http\ApiUrl;
use Cbox\Billing\Client\Testing\FakePublicId;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Billing v2 serves its API on its own host at `/v1`; the app host still answers `/api/v1`.
 * `api_url` names the new address; an app that only set the old `base_url` keeps working.
 */

it('resolves the API URL from the new setting, the old one or the hosted default', function (?string $api, ?string $legacy, string $expected): void {
    expect(ApiUrl::resolve($api, $legacy))->toBe($expected);
})->with([
    'api_url as it is' => ['https://api.cboxbilling.com/v1', 'https://app.cboxbilling.com', 'https://api.cboxbilling.com/v1'],
    'api_url without its trailing slash' => ['https://api.billing.test/v1/', null, 'https://api.billing.test/v1'],
    'old app root gets /api/v1' => [null, 'https://app.cboxbilling.com', 'https://app.cboxbilling.com/api/v1'],
    'old app root with a slash' => [null, 'https://app.cboxbilling.com/', 'https://app.cboxbilling.com/api/v1'],
    'old setting already on /v1' => [null, 'https://api.cboxbilling.com/v1', 'https://api.cboxbilling.com/v1'],
    'old setting already on /api/v1' => [null, 'https://billing.test/api/v1', 'https://billing.test/api/v1'],
    'blank api_url falls back' => ['  ', 'https://billing.test', 'https://billing.test/api/v1'],
    'nothing set: the hosted API' => [null, null, ApiUrl::HOSTED],
]);

it('gives apps the same API URL the transports use', function (): void {
    config(['billing-client.api_url' => null, 'billing-client.base_url' => 'https://app.billing.test']);
    expect(ApiUrl::fromConfig(config()))->toBe('https://app.billing.test/api/v1');

    config(['billing-client.api_url' => 'https://api.billing.test/v1']);
    expect(ApiUrl::fromConfig(config()))->toBe('https://api.billing.test/v1');
});

it('calls the API host at /v1 when api_url is set', function (): void {
    config([
        'billing-client.api_url' => 'https://api.billing.test/v1',
        'billing-client.base_url' => 'https://app.billing.test',
        'billing-client.api_token' => 'token',
        'billing-client.definition_token' => 'definition-token',
        'billing-client.service_access.url' => null,
        'billing-client.cache_store' => 'array',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'api.billing.test/v1/plans' => Http::response(['data' => []]),
        'api.billing.test/v1/leases' => Http::response(['lease_id' => 'lease_1', 'granted' => 5, 'expires_at' => '2030-01-01T00:00:00Z']),
        'api.billing.test/v1/access/services/svc/check' => Http::response(['allowed' => true]),
        'api.billing.test/v1/definitions' => Http::response(['data' => []]),
    ]);

    app(BillingManagement::class)->plans();
    app(BillingTransport::class)->lease('org_a', 'api.calls', 5);
    expect(app(ServiceAccessTransport::class)->check('svc', 'usr_1')->allowed)->toBeTrue();
    app(DefinitionTransport::class)->sync(DefinitionManifest::fromJson((string) json_encode(['version' => 'v1', 'features' => [], 'meters' => []])));

    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'app.billing.test') || str_contains($request->url(), '/api/v1'));
});

it('keeps an app on the old base_url working through /api/v1', function (): void {
    config([
        'billing-client.api_url' => null,
        'billing-client.base_url' => 'https://app.billing.test',
        'billing-client.api_token' => 'token',
    ]);
    Http::preventStrayRequests();
    Http::fake(['app.billing.test/api/v1/plans' => Http::response(['data' => []])]);

    expect(app(BillingManagement::class)->plans())->toBe([]);
});

it('treats an old service access root as the app host and a /v1 URL as the API', function (string $configured, string $called): void {
    config([
        'billing-client.api_url' => 'https://api.billing.test/v1',
        'billing-client.api_token' => 'token',
        'billing-client.service_access.url' => $configured,
        'billing-client.service_access.token' => 'access-token',
    ]);
    Http::preventStrayRequests();
    Http::fake([$called => Http::response(['allowed' => true])]);

    expect(app(ServiceAccessTransport::class)->check('svc', 'usr_1')->allowed)->toBeTrue();
})->with([
    'old root' => ['https://app.billing.test', 'https://app.billing.test/api/v1/access/services/svc/check'],
    'api host' => ['https://access.billing.test/v1', 'https://access.billing.test/v1/access/services/svc/check'],
]);

it('hands out public ids shaped like Billing\'s in the fakes', function (): void {
    expect(FakePublicId::make('inv', 1))->toBe('inv_0000000000000000000001')
        ->and(FakePublicId::make('sub', 62))->toBe('sub_0000000000000000000010')
        ->and(FakePublicId::make('lir', 61))->toMatch('/^lir_[0-9A-Za-z]{22}$/');

    expect(fn () => FakePublicId::make('Bad', 1))->toThrow(InvalidArgumentException::class);
});
