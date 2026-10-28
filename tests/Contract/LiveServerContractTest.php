<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Buffers\ArrayUsageBuffer;
use Cbox\Billing\Client\Enums\FailurePolicy;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Leasing\LeaseManager;
use Cbox\Billing\Client\Reporting\UsageReporter;
use Cbox\Billing\Client\Stores\CacheLeaseStore;
use Cbox\Billing\Client\Tests\Support\ServerFixture;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;

/*
 * The contract suite: the REAL HTTP transports against a LIVE Cbox Billing server, and every
 * live response held to the recorded fixtures in `tests/Fixtures/server`. It is what proves the
 * fixtures (and so the fakes built on them) still match the server.
 *
 * Skipped unless BILLING_CONTRACT_URL and BILLING_CONTRACT_TOKEN are set. It refuses a live
 * token (`cbl_…`): run it with a SANDBOX token (`cbt_…`) only. It writes to that sandbox: it
 * provisions an organization and, with BILLING_CONTRACT_PLAN, subscribes and cancels it.
 *
 *   BILLING_CONTRACT_URL     base URL, e.g. https://app.cboxbilling.com
 *   BILLING_CONTRACT_TOKEN   a sandbox API token (operator or product-bound)
 *   BILLING_CONTRACT_ORG     optional: act for this existing org instead of provisioning one
 *                            (required for an org-scoped token)
 *   BILLING_CONTRACT_PLAN    optional: a plan key to subscribe the org to, then cancel
 *   BILLING_CONTRACT_METER   optional: a meter key to lease one unit of
 *
 *   composer test:contract
 */
uses()->group('contract');

function contractEnv(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

function contractUrl(): string
{
    return (string) contractEnv('BILLING_CONTRACT_URL');
}

function contractToken(): string
{
    return (string) contractEnv('BILLING_CONTRACT_TOKEN');
}

/** The org every test acts for: the configured one, or one provisioned once per run. */
function contractOrg(): string
{
    static $org = null;

    return $org ??= contractEnv('BILLING_CONTRACT_ORG') ?? 'sdk-contract-'.bin2hex(random_bytes(6));
}

function contractManagement(?string $token = null): HttpManagementTransport
{
    return new HttpManagementTransport(new HttpFactory, contractUrl(), $token ?? contractToken(), 15);
}

function contractEnforcement(?string $token = null): HttpBillingTransport
{
    return new HttpBillingTransport(new HttpFactory, contractUrl(), $token ?? contractToken(), 15);
}

/**
 * A raw call, for holding the live body to a fixture.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: int, 1: mixed}
 */
function contractRaw(string $method, string $path, array $payload = []): array
{
    $response = (new HttpFactory)->asJson()->acceptJson()->withToken(contractToken())->timeout(15)
        ->send($method, rtrim(contractUrl(), '/').$path, $method === 'GET' ? [] : ['json' => $payload]);

    return [$response->status(), $response->json()];
}

function expectShape(string $fixture, int $status, mixed $body): void
{
    $recorded = ServerFixture::load($fixture);

    expect($status)->toBe($recorded->status, "status of [{$fixture}]")
        ->and($recorded->missingFrom($body))->toBe([], "fields of [{$fixture}] the server no longer sends");
}

beforeEach(function (): void {
    if (contractEnv('BILLING_CONTRACT_URL') === null || contractEnv('BILLING_CONTRACT_TOKEN') === null) {
        $this->markTestSkipped('Set BILLING_CONTRACT_URL and BILLING_CONTRACT_TOKEN to run the contract suite.');
    }

    if (str_starts_with(contractToken(), 'cbl_')) {
        $this->fail('The contract suite writes to the server it runs against. Use a sandbox (cbt_) token, never a live one.');
    }
});

it('answers a bad token with 401, and the enforcement client refuses it under the allow policy', function (): void {
    $bogus = 'cbt_'.str_repeat('0', 48);

    try {
        contractManagement($bogus)->plans();
        $this->fail('A bad token was accepted.');
    } catch (BillingRefused $e) {
        expect($e->status)->toBe(401);
    }

    $store = new CacheLeaseStore(new Repository(new ArrayStore));
    $transport = contractEnforcement($bogus);
    $buffer = new ArrayUsageBuffer;
    $client = new BillingClient($store, $buffer, new LeaseManager($transport, $store), new UsageReporter($transport, $buffer), $transport, FailurePolicy::Allow);

    expect(fn () => $client->reserve(contractOrg(), 'api.calls', 1))->toThrow(BillingRefused::class);
});

it('serves the plan catalog in the recorded shape', function (): void {
    [$status, $body] = contractRaw('GET', '/api/v1/plans');
    expectShape('plans', $status, $body);

    expect(contractManagement()->plans())->toBeArray();
});

it('provisions an organization in the recorded shape', function (): void {
    if (contractEnv('BILLING_CONTRACT_ORG') !== null) {
        $this->markTestSkipped('BILLING_CONTRACT_ORG is set; not provisioning.');
    }

    [$status, $body] = contractRaw('PUT', '/api/v1/organizations/'.rawurlencode(contractOrg()), ['name' => 'SDK contract suite']);
    expectShape('organization-upsert', $status, $body);

    // A repeat is an idempotent update: 200, same shape.
    contractManagement()->ensureOrganization(contractOrg(), ['name' => 'SDK contract suite']);
});

it('reads a brand-new organization as having no subscription (INT-1)', function (): void {
    if (contractEnv('BILLING_CONTRACT_ORG') !== null) {
        $this->markTestSkipped('BILLING_CONTRACT_ORG may already be subscribed.');
    }

    [$status, $body] = contractRaw('GET', '/api/v1/subscriptions/'.rawurlencode(contractOrg()));
    expectShape('subscription-missing', $status, $body);

    expect(contractManagement()->subscription(contractOrg()))->toBeNull();
});

it('serves entitlements in the recorded shape', function (): void {
    [$status, $body] = contractRaw('GET', '/api/v1/entitlements/'.rawurlencode(contractOrg()));
    expectShape('entitlements', $status, $body);

    expect(contractEnforcement()->entitlements(contractOrg())->org)->toBe(contractOrg());
});

it('serves usage and invoices in the recorded shape', function (): void {
    $org = rawurlencode(contractOrg());

    foreach (['usage-summary' => "/api/v1/usage/{$org}", 'invoices' => "/api/v1/invoices/{$org}"] as $fixture => $path) {
        [$status, $body] = contractRaw('GET', $path);
        expectShape($fixture, $status, $body);
    }

    $management = contractManagement();
    $management->usage(contractOrg());
    $management->invoices(contractOrg());
});

it('leases in the recorded shape, with an expiry the client reads', function (): void {
    $meter = contractEnv('BILLING_CONTRACT_METER');

    if ($meter === null) {
        $this->markTestSkipped('Set BILLING_CONTRACT_METER to lease.');
    }

    [$status, $body] = contractRaw('POST', '/api/v1/leases', ['org' => contractOrg(), 'meter' => $meter, 'size' => 1]);
    expectShape('lease', $status, $body);

    expect(contractEnforcement()->lease(contractOrg(), $meter, 1)->expiresAt)->not->toBeNull();
});

it('subscribes, reads back, and cancels at period end by default', function (): void {
    $plan = contractEnv('BILLING_CONTRACT_PLAN');

    if ($plan === null) {
        $this->markTestSkipped('Set BILLING_CONTRACT_PLAN to subscribe.');
    }

    [$status, $body] = contractRaw('POST', '/api/v1/subscriptions', ['org' => contractOrg(), 'plan' => $plan]);
    expectShape('subscribe', $status, $body);

    $management = contractManagement();

    [$status, $body] = contractRaw('GET', '/api/v1/subscriptions/'.rawurlencode(contractOrg()));
    expectShape('subscription', $status, $body);
    expect($management->subscription(contractOrg())?->plan)->toBe($plan);

    $canceled = (new BillingManagement($management))->cancel(contractOrg());
    expect($canceled->cancelsAtPeriodEnd())->toBeTrue();
});
