<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\ValueObjects\LimitIncreaseInput;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;

uses()->group('contract', 'limit-contract');

it('uses real server limits requests decisions revocation and cancellation', function (): void {
    $url = getenv('BILLING_LIMIT_CONTRACT_URL');
    $token = getenv('BILLING_LIMIT_CONTRACT_TOKEN');
    $org = getenv('BILLING_LIMIT_CONTRACT_ORG');
    if (! is_string($url) || ! is_string($token) || ! is_string($org)) {
        $this->markTestSkipped('A disposable local limit-contract server is not configured.');
    }
    if (! in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) || ! str_starts_with($token, 'cbt_') || ! str_starts_with($org, 'sdk-limit-contract-')) {
        throw new RuntimeException('This write test only permits a disposable local sandbox fixture.');
    }
    $http = new Factory;
    $client = new BillingManagement(new HttpManagementTransport($http, $url, $token));
    $limits = $client->resourceLimits($org, 'limit-contract');
    expect($limits['max_sites']->limit)->toBe(10);
    $input = new LimitIncreaseInput((string) Str::uuid(), 'max_sites', 30, 'Local contract acceptance');
    $request = $client->requestLimitIncrease($org, $input, 'limit-contract');
    expect($request->status)->toBe('pending')->and($client->requestLimitIncrease($org, $input, 'limit-contract')->id)->toBe($request->id);
    $path = $url.'/api/v1/organizations/'.$org.'/limit-increase-requests/'.$request->id;
    $decision = $http->withToken($token)->acceptJson()->post($path.'/decision?product=limit-contract', [
        'decision' => 'approved', 'approved_limit' => 20, 'reason' => 'Local fixture approval', 'policy_revision' => 'test-v1',
        'evidence' => ['test:local-http-contract'], 'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
    ]);
    expect($decision->status())->toBe(200)->and($client->resourceLimits($org, 'limit-contract')['max_sites']->limit)->toBe(20);
    $rows = $client->limitIncreaseRequests($org, product: 'limit-contract');
    expect($rows->data[0]->approvedLimit)->toBe(20)->and($rows->data[0]->status)->toBe('approved');
    $revocation = $http->withToken($token)->acceptJson()->post($path.'/revoke?product=limit-contract', ['reason' => 'Local acceptance finished']);
    expect($revocation->status())->toBe(200)->and($client->resourceLimits($org, 'limit-contract')['max_sites']->limit)->toBe(10);
    $next = $client->requestLimitIncrease($org, new LimitIncreaseInput((string) Str::uuid(), 'max_sites', 15, 'Cancellation check'), 'limit-contract');
    expect($client->cancelLimitIncrease($org, $next->id, 'limit-contract')->status)->toBe('cancelled');
});
