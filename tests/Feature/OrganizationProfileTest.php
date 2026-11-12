<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Exceptions\UnsupportedOperation;
use Cbox\Billing\Client\Facades\BillingManager;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/*
 * 0.4.6: the organization upsert with billing's answer, so an app can show what billing stores.
 */

function profileClient(HttpFactory $http): BillingManagement
{
    return new BillingManagement(new HttpManagementTransport($http, 'https://billing.test/api/v1', 'token', 5));
}

it('returns what billing stores for the organization', function (): void {
    $http = new HttpFactory;
    $http->fake(['billing.test/api/v1/organizations/org_1' => $http->response(['organization' => [
        'id' => '01J', 'external_ref' => 'org_1', 'name' => 'Klubben', 'billing_email' => 'k@klubben.test',
        'billing_phone' => '+45 32 12 34 56', 'billing_address_line1' => 'Vejen 1', 'billing_postal_code' => '8000', 'billing_city' => 'Aarhus',
        'billing_country' => 'DK', 'tax_id' => 'DK12345678', 'tax_id_validated' => true, 'account' => 'owned',
    ]], 200)]);

    $profile = profileClient($http)->upsertOrganization('org_1', ['name' => 'Klubben', 'tax_id' => 'DK12345678']);

    expect($profile->id)->toBe('01J')
        ->and($profile->billingPhone)->toBe('+45 32 12 34 56')
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
        ->and($profile->billingPhone)->toBeNull()
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

/*
 * 0.6.2: the read-only profile, for an app that shows the billing details next to a link to the
 * hosted portal and keeps no form of its own. It never creates the organization.
 */

it('reads the profile with a GET and sends nothing', function (): void {
    $http = new HttpFactory;
    $http->fake(['billing.test/api/v1/organizations/org_1' => $http->response(['organization' => [
        'id' => 'cust_01J', 'external_ref' => 'org_1', 'name' => 'Klubben', 'billing_email' => 'k@klubben.test',
        'billing_phone' => '+45 32 12 34 56', 'billing_address_line1' => 'Vejen 1', 'billing_postal_code' => '8000', 'billing_city' => 'Aarhus',
        'billing_country' => 'DK', 'billing_subdivision' => null, 'billing_currency' => 'DKK', 'purchaser_type' => 'public_body',
        'ean_number' => '5790000000005', 'order_reference' => 'Att. Jens', 'tax_id' => 'DK12345678', 'tax_id_validated' => true, 'account' => 'owned',
    ]], 200)]);

    $profile = profileClient($http)->organizationProfile('org_1');

    expect($profile->id)->toBe('cust_01J')
        ->and($profile->name)->toBe('Klubben')
        ->and($profile->billingEmail)->toBe('k@klubben.test')
        ->and($profile->billingPhone)->toBe('+45 32 12 34 56')
        ->and($profile->addressLine1)->toBe('Vejen 1')
        ->and($profile->country)->toBe('DK')
        ->and($profile->subdivision)->toBeNull()
        ->and($profile->currency)->toBe('DKK')
        ->and($profile->purchaserType)->toBe('public_body')
        ->and($profile->eanNumber)->toBe('5790000000005')
        ->and($profile->orderReference)->toBe('Att. Jens')
        ->and($profile->taxId)->toBe('DK12345678')
        ->and($profile->taxIdValidated)->toBeTrue()
        ->and($profile->shared)->toBeFalse();

    $http->assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://billing.test/api/v1/organizations/org_1'
        && $request->hasHeader('Authorization', 'Bearer token'));
    $http->assertSentCount(1);
});

it('throws billing\'s 404 for an unknown organization and its 403 for a missing scope', function (): void {
    $http = new HttpFactory;
    $http->fake([
        'billing.test/api/v1/organizations/org_gone' => $http->response(['error' => 'Unknown organization.'], 404),
        'billing.test/api/v1/organizations/org_1' => $http->response(['error' => 'insufficient_scope', 'required_scope' => 'organizations:read'], 403),
        'billing.test/api/v1/organizations/org_2' => $http->response(['nothing' => true], 200),
    ]);

    expect(fn () => profileClient($http)->organizationProfile('org_gone'))->toThrow(fn (BillingRefused $e) => expect($e->status)->toBe(404))
        ->and(fn () => profileClient($http)->organizationProfile('org_1'))->toThrow(fn (BillingRefused $e) => expect($e->status)->toBe(403))
        ->and(fn () => profileClient($http)->organizationProfile('org_2'))->toThrow(TransportException::class);
});

it('reads from the fake what was provisioned, and never creates', function (): void {
    $fake = new FakeManagementTransport;
    $billing = new BillingManagement($fake);
    $billing->upsertOrganization('org_1', ['name' => 'Klubben', 'billing_city' => 'Aarhus', 'billing_country' => 'DK', 'billing_phone' => '+45 1', 'tax_id' => 'DK12345678']);

    $profile = $billing->organizationProfile('org_1');

    expect($profile->name)->toBe('Klubben')->and($profile->city)->toBe('Aarhus')->and($profile->country)->toBe('DK')
        ->and($profile->billingPhone)->toBe('+45 1')->and($profile->taxId)->toBe('DK12345678')->and($profile->shared)->toBeFalse()
        ->and(fn () => $billing->organizationProfile('org_unknown'))->toThrow(fn (BillingRefused $e) => expect($e->status)->toBe(404))
        ->and($fake->organizations())->toHaveKeys(['org_1'])->not->toHaveKey('org_unknown');
});

it('refuses a transport without the read contract', function (): void {
    $older = Mockery::mock(ManagementTransport::class);

    expect(fn () => (new BillingManagement($older))->organizationProfile('org_1'))->toThrow(UnsupportedOperation::class);
});

it('reaches the read through the facade', function (): void {
    $fake = new FakeManagementTransport;
    $fake->upsertOrganization('org_1', ['name' => 'Klubben']);
    app()->instance(BillingManagement::class, new BillingManagement($fake));

    expect(BillingManager::organizationProfile('org_1')->name)->toBe('Klubben');
});
