<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/*
 * 0.4.6: the organization upsert with billing's answer, so an app can show what billing stores.
 */

function profileClient(HttpFactory $http): BillingManagement
{
    return new BillingManagement(new HttpManagementTransport($http, 'https://billing.test', 'token', 5));
}

it('returns what billing stores for the organization', function (): void {
    $http = new HttpFactory;
    $http->fake(['billing.test/api/v1/organizations/org_1' => $http->response(['organization' => [
        'id' => '01J', 'external_ref' => 'org_1', 'name' => 'Klubben', 'billing_email' => 'k@klubben.test',
        'billing_address_line1' => 'Vejen 1', 'billing_postal_code' => '8000', 'billing_city' => 'Aarhus',
        'billing_country' => 'DK', 'tax_id' => 'DK12345678', 'tax_id_validated' => true, 'account' => 'owned',
    ]], 200)]);

    $profile = profileClient($http)->upsertOrganization('org_1', ['name' => 'Klubben', 'tax_id' => 'DK12345678']);

    expect($profile->id)->toBe('01J')
        ->and($profile->externalRef)->toBe('org_1')
        ->and($profile->city)->toBe('Aarhus')
        ->and($profile->taxId)->toBe('DK12345678')
        ->and($profile->taxIdValidated)->toBeTrue()
        ->and($profile->taxIdSupported)->toBeTrue()
        ->and($profile->shared)->toBeFalse();

    $http->assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['tax_id'] === 'DK12345678');
});

it('reads a shared account and an older server that does not answer the VAT number', function (): void {
    $http = new HttpFactory;
    $http->fake(['billing.test/*' => $http->response(['organization' => ['id' => '01J', 'name' => 'Klubben', 'billing_city' => null, 'account' => 'shared']], 200)]);

    $profile = profileClient($http)->upsertOrganization('org_1', ['name' => 'Klubben']);

    expect($profile->shared)->toBeTrue()
        ->and($profile->city)->toBeNull()
        ->and($profile->taxIdSupported)->toBeFalse()
        ->and($profile->taxIdValidated)->toBeNull();
});

it('throws billing\'s refusal, and on an answer without the organization', function (): void {
    $http = new HttpFactory;
    $http->fake(['billing.test/api/v1/organizations/org_1' => $http->response(['error' => 'nope'], 403), 'billing.test/api/v1/organizations/org_2' => $http->response([], 200)]);

    expect(fn () => profileClient($http)->upsertOrganization('org_1', ['name' => 'Klubben']))->toThrow(BillingRefused::class)
        ->and(fn () => profileClient($http)->upsertOrganization('org_2', ['name' => 'Klubben']))->toThrow(TransportException::class);
});

it('answers from the fake as the server does', function (): void {
    $profile = (new BillingManagement(new FakeManagementTransport))->upsertOrganization('org_1', ['name' => 'Klubben', 'billing_city' => 'Aarhus', 'tax_id' => 'DK12345678']);

    expect($profile->name)->toBe('Klubben')->and($profile->city)->toBe('Aarhus')->and($profile->taxId)->toBe('DK12345678')->and($profile->taxIdValidated)->toBeFalse()->and($profile->shared)->toBeFalse();
});

it('refuses a transport without the contract', function (): void {
    $older = Mockery::mock(ManagementTransport::class);

    expect(fn () => (new BillingManagement($older))->upsertOrganization('org_1', ['name' => 'Klubben']))->toThrow(UnsupportedOperation::class);
});
