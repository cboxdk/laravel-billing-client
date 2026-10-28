<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * INT-8: a client error used to carry no status, so the failure policy could not tell "billing
 * said no" from "billing is down". A revoked token (401) on a lease refill was treated as an
 * outage, and the default fail-open policy then admitted every request. These tests drive the
 * REAL container wiring and HTTP transport, so the classification is proven end to end.
 */
function httpBillingClient(string $policy): BillingClient
{
    config([
        'billing-client.base_url' => 'https://billing.test',
        'billing-client.api_token' => 'revoked-token',
        'billing-client.fail' => $policy,
        'billing-client.cache_store' => 'array',
    ]);

    return app(BillingClient::class);
}

it('refuses a reserve when the lease refill is answered 401, even under the allow policy', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response(['error' => 'Invalid API token.'], 401)]);

    $client = httpBillingClient('allow');

    expect(fn () => $client->reserve('org_a', 'api.calls', 5))->toThrow(BillingRefused::class)
        ->and($client->can('org_a', 'api.calls', 5))->toBeFalse();
});

it('refuses a multi-meter reserve on a 403, even under the allow policy', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response(['error' => 'Not permitted.'], 403)]);

    $client = httpBillingClient('allow');

    expect(fn () => $client->reserve('org_a', ['api.calls' => 5, 'storage.gb' => 1]))->toThrow(BillingRefused::class);
});

it('still fails open on a 5xx under the allow policy', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response(['message' => 'Server Error'], 503)]);

    $reservation = httpBillingClient('allow')->reserve('org_a', 'api.calls', 5);

    expect($reservation->backedByLease)->toBeFalse();
});

it('fails open on a 429, which is load shedding rather than a refusal', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '17'])]);

    expect(httpBillingClient('allow')->reserve('org_a', 'api.calls', 5)->backedByLease)->toBeFalse();
});

it('fails closed on a 5xx under the deny policy, surfacing BillingUnavailable', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response([], 502)]);

    expect(fn () => httpBillingClient('deny')->reserve('org_a', 'api.calls', 5))->toThrow(BillingUnavailable::class);
});

it('carries the status, body and Retry-After of a refusal', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response(['error' => 'Invalid API token.'], 401)]);

    try {
        httpBillingClient('allow')->reserve('org_a', 'api.calls', 5);
        $this->fail('Expected a refusal.');
    } catch (BillingRefused $e) {
        expect($e->status)->toBe(401)
            ->and($e->body)->toBe('{"error":"Invalid API token."}')
            ->and($e->retryAfter)->toBeNull()
            ->and($e->endpoint)->toBe('/api/v1/leases')
            ->and($e)->toBeInstanceOf(TransportException::class);
    }
});

it('carries the status and Retry-After of a 429', function (): void {
    Http::fake(['billing.test/api/v1/plans' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '17'])]);
    config(['billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 't']);

    try {
        app(BillingManagement::class)->plans();
        $this->fail('Expected billing to be unavailable.');
    } catch (BillingUnavailable $e) {
        expect($e->status)->toBe(429)
            ->and($e->retryAfter)->toBe(17)
            ->and($e->body)->toContain('Too Many Attempts.');
    }
});

it('reads an HTTP-date Retry-After as seconds from now', function (): void {
    $at = gmdate('D, d M Y H:i:s', time() + 120).' GMT';
    Http::fake(['billing.test/api/v1/plans' => Http::response('', 503, ['Retry-After' => $at])]);
    config(['billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 't']);

    try {
        app(BillingManagement::class)->plans();
        $this->fail('Expected billing to be unavailable.');
    } catch (BillingUnavailable $e) {
        expect($e->retryAfter)->toBeGreaterThanOrEqual(115)->toBeLessThanOrEqual(120);
    }
});

it('classifies a connection error as unavailable with no status', function (): void {
    Http::fake(static fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
    config(['billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 't']);

    try {
        app(BillingManagement::class)->plans();
        $this->fail('Expected billing to be unavailable.');
    } catch (BillingUnavailable $e) {
        expect($e->status)->toBeNull()
            ->and($e->body)->toBeNull()
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('classifies a validation error (422) as a refusal', function (): void {
    Http::fake(['billing.test/api/v1/subscriptions' => Http::response(['message' => 'The plan field is required.', 'errors' => ['plan' => ['The plan field is required.']]], 422)]);
    config(['billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 't']);

    expect(fn () => app(BillingManagement::class)->subscribe('org_a', 'pro'))->toThrow(BillingRefused::class);
});

it('reports a refused usage flush as not reported, keeping the totals buffered', function (): void {
    Http::fake([
        'billing.test/api/v1/leases' => Http::response(['lease_id' => 'lease_1', 'granted' => 100, 'expires_at' => '2026-10-28T12:05:00+00:00']),
        'billing.test/api/v1/usage' => Http::response(['error' => 'Invalid API token.'], 401),
    ]);

    $client = httpBillingClient('allow');
    $client->commit($client->reserve('org_a', 'api.calls', 5), 5);

    expect($client->report('org_a'))->toBe(0);
});
