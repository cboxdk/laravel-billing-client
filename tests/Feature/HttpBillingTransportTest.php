<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpBillingTransport;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeHttpTransport(): HttpBillingTransport
{
    return new HttpBillingTransport(
        app(HttpFactory::class),
        'https://billing.test',
        'secret-token',
    );
}

it('leases a slice over HTTP with a bearer token', function (): void {
    Http::fake([
        'billing.test/api/v1/leases' => Http::response([
            'lease_id' => 'lease_1',
            'granted' => 40,
            'expires_at' => 1_700_000_000_000,
        ]),
    ]);

    $grant = makeHttpTransport()->lease('org_a', 'api.calls', 100);

    expect($grant->leaseId)->toBe('lease_1')
        ->and($grant->granted)->toBe(40)
        ->and($grant->expiresAt)->toBe(1_700_000_000_000);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->url() === 'https://billing.test/api/v1/leases'
        && $request['size'] === 100);
});

it('posts cumulative usage entries', function (): void {
    Http::fake(['billing.test/api/v1/usage' => Http::response(['ok' => true])]);

    makeHttpTransport()->reportUsage('org_a', [
        new CumulativeUsage('org_a', 'api.calls', 42, 7),
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/usage'
        && $request['entries'][0]['cumulative'] === 42
        && $request['entries'][0]['seq'] === 7);
});

it('reads an entitlement set over HTTP', function (): void {
    Http::fake([
        'billing.test/api/v1/entitlements/*' => Http::response([
            'meters' => [
                'api.calls' => ['enabled' => true, 'allowance' => 1000, 'weight' => 1.5, 'overage' => 'bill'],
            ],
        ]),
    ]);

    $entitlements = makeHttpTransport()->entitlements('org_a');

    expect($entitlements->enabled('api.calls'))->toBeTrue()
        ->and($entitlements->for('api.calls')?->allowance)->toBe(1000)
        ->and($entitlements->for('api.calls')?->weight)->toBe(1.5)
        ->and($entitlements->for('api.calls')?->blocksOnOverage())->toBeFalse();
});

it('raises a transport exception on a non-2xx response (deny-by-default)', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response([], 503)]);

    expect(fn () => makeHttpTransport()->lease('org_a', 'api.calls', 100))
        ->toThrow(TransportException::class);
});

it('raises a transport exception on a malformed body', function (): void {
    Http::fake(['billing.test/api/v1/leases' => Http::response('not-a-json-object', 200)]);

    expect(fn () => makeHttpTransport()->lease('org_a', 'api.calls', 100))
        ->toThrow(TransportException::class);
});
