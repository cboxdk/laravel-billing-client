<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Http\WireId;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * 0.6 must work against Billing before v2 (numeric ids) and Billing v2 (public ids), so apps can
 * bump the client first and Billing can deploy after. Every id read off the wire accepts both and
 * hands on a string.
 */

function numericIdClient(): BillingManagement
{
    return new BillingManagement(new HttpManagementTransport(app(Factory::class), 'https://billing.test/api/v1', 'token'));
}

/** @return array<string, mixed> */
function recordedBody(string $fixture): array
{
    $recorded = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/server/'.$fixture.'.json'), true, 512, JSON_THROW_ON_ERROR);

    return $recorded['body'];
}

/** @return array<string, mixed> */
function limitRequestWire(int|string $id): array
{
    return ['id' => $id, 'request_key' => '0d01641e-ff77-4465-9ba9-20e372bd5670', 'feature' => 'max_sites', 'status' => 'cancelled',
        'observed_limit' => 10, 'requested_limit' => 30, 'reason' => 'More sites', 'approved_limit' => null, 'decision_reason' => null,
        'decided_at' => null, 'expires_at' => null, 'revoked_at' => null, 'revocation_reason' => null, 'created_at' => '2026-11-06T12:00:00Z'];
}

it('normalises a wire id to a string and refuses what is not one', function (mixed $value, ?string $expected): void {
    expect(WireId::from($value))->toBe($expected);
})->with([
    'old numeric id' => [17, '17'],
    'public id' => ['inv_7Hq2kV9xTq3LmN0pR4sW1z', 'inv_7Hq2kV9xTq3LmN0pR4sW1z'],
    'numeric string' => ['17', '17'],
    'zero' => [0, null],
    'negative' => [-3, null],
    'empty' => ['', null],
    'blank' => ['  ', null],
    'float' => [1.5, null],
    'bool' => [true, null],
    'missing' => [null, null],
]);

it('reads the change feed from a Billing that sends numeric ids', function (int|string $id, int|string $organization, string $expectedId, string $expectedOrganization): void {
    $body = recordedBody('subscriptions');
    $body['data'][0]['id'] = $id;
    $body['data'][0]['organization_id'] = $organization;
    Http::fake(['billing.test/api/v1/subscriptions*' => Http::response($body)]);

    $change = numericIdClient()->subscriptions()->data[0];

    expect($change->id)->toBe($expectedId)
        ->and($change->organizationId)->toBe($expectedOrganization);
})->with([
    'Billing before v2' => [42, 7, '42', '7'],
    'Billing v2' => ['sub_YzRj0WiAJUjSMwcqrCirsa', '01JBX7Q3W9T0S2M4V6Y8Z1A3C5', 'sub_YzRj0WiAJUjSMwcqrCirsa', '01JBX7Q3W9T0S2M4V6Y8Z1A3C5'],
]);

it('reads an issued invoice from a Billing that sends a numeric id', function (int|string $id, string $expected): void {
    $body = recordedBody('invoice-detail');
    $body['id'] = $id;
    Http::fake(['billing.test/api/v1/invoices/org_a/INV-1001' => Http::response($body)]);

    expect(numericIdClient()->invoice('org_a', 'INV-1001')->id)->toBe($expected);
})->with([
    'Billing before v2' => [17, '17'],
    'Billing v2' => ['inv_7Hq2kV9xTq3LmN0pR4sW1z', 'inv_7Hq2kV9xTq3LmN0pR4sW1z'],
]);

it('reads a refund held for approval with a numeric or public approval id', function (int|string $approval, string $expected): void {
    Http::fake(['billing.test/api/v1/invoices/org_1/INV-1/refunds' => Http::response(['status' => 'pending_approval', 'approval' => $approval], 202)]);

    expect(numericIdClient()->refundInvoice('org_1', 'INV-1', 'refund-1')->approvalId)->toBe($expected);
})->with([
    'Billing before v2' => [42, '42'],
    'Billing v2' => ['apr_3VbN7mQ2xLk9JhG4fD1sZa', 'apr_3VbN7mQ2xLk9JhG4fD1sZa'],
]);

it('reads limits and cancels a limit-increase request by a numeric id', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'billing.test/api/v1/organizations/a/limits' => Http::response(['scope' => 'organization', 'monetary_exposure_included' => false, 'limits' => [
            'max_sites' => ['key' => 'max_sites', 'enabled' => true, 'limit' => 20, 'base_limit' => 10, 'grant_request_id' => 12,
                'source' => 'approved_request', 'expires_at' => '2030-12-06T12:00:00Z', 'observed_at' => '2026-11-06T12:00:00Z'],
        ]]),
        'billing.test/api/v1/organizations/a/limit-increase-requests?page=1' => Http::response(['data' => [limitRequestWire(12)], 'current_page' => 1, 'last_page' => 1]),
        'billing.test/api/v1/organizations/a/limit-increase-requests/12/cancel' => Http::response(limitRequestWire(12)),
    ]);
    $client = numericIdClient();

    expect($client->resourceLimits('a')['max_sites']->grantRequestId)->toBe('12');

    $listed = $client->limitIncreaseRequests('a')->data[0];
    expect($listed->id)->toBe('12')
        ->and($client->cancelLimitIncrease('a', $listed->id)->status)->toBe('cancelled');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://billing.test/api/v1/organizations/a/limit-increase-requests/12/cancel');
});

it('still refuses an id that is neither a positive number nor a string', function (mixed $id): void {
    $body = recordedBody('invoice-detail');
    $body['id'] = $id;
    Http::fake(['billing.test/api/v1/invoices/org_a/INV-1001' => Http::response($body)]);

    expect(fn () => numericIdClient()->invoice('org_a', 'INV-1001'))->toThrow(BillingUnavailable::class);
})->with([[0], [''], [null], [1.5]]);

it('takes the old app root plus /api/v1 when only BILLING_CLIENT_BASE_URL is set', function (): void {
    // The shipped config reads api_url from BILLING_CLIENT_API_URL only; with it unset, the
    // old setting decides the address.
    expect(config('billing-client.api_url'))->toBeNull();

    config(['billing-client.base_url' => 'https://app.cboxbilling.test', 'billing-client.api_token' => 'token']);
    Http::preventStrayRequests();
    Http::fake(['app.cboxbilling.test/api/v1/invoices/org_a/INV-1001' => Http::response([...recordedBody('invoice-detail'), 'id' => 17])]);

    expect(app(BillingManagement::class)->invoice('org_a', 'INV-1001')->id)->toBe('17');
});
