<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\DefinitionTransport;
use Cbox\Billing\Client\Definitions\DefinitionManifest;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Http\HttpDefinitionTransport;
use Cbox\Billing\Client\Testing\FakeDefinitionTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function definitionDocument(): string
{
    return '{"version":"1","features":[{"key":"sites.domains","name":"Domains","type":"boolean"}],"meters":[]}';
}

it('publishes exactly the declaration and accepts the matching response', function (): void {
    Http::fake(['https://billing.test/api/v1/definitions' => Http::response(['data' => [
        ['kind' => 'feature', 'key' => 'sites.domains', 'version' => '1', 'retired_at' => null],
    ]])]);
    $transport = new HttpDefinitionTransport(app(Factory::class), 'https://billing.test/api/v1', 'definition-secret');
    $transport->sync(DefinitionManifest::fromJson(definitionDocument()));
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && $request->hasHeader('Authorization', 'Bearer definition-secret')
        && $request->data() === json_decode(definitionDocument(), true));
});

it('refuses incomplete, wrong-version, and duplicate acknowledgements', function (array $data): void {
    Http::fake(['*' => Http::response(['data' => $data])]);
    $transport = new HttpDefinitionTransport(app(Factory::class), 'https://billing.test/api/v1', 'secret');
    expect(fn () => $transport->sync(DefinitionManifest::fromJson(definitionDocument())))->toThrow(BillingUnavailable::class);
})->with([
    'missing' => [[]],
    'wrong version' => [[['kind' => 'feature', 'key' => 'sites.domains', 'version' => '0', 'retired_at' => null]]],
    'duplicate' => [[['kind' => 'feature', 'key' => 'sites.domains', 'version' => '1', 'retired_at' => null], ['kind' => 'feature', 'key' => 'sites.domains', 'version' => '1', 'retired_at' => null]]],
]);

it('never follows redirects with the publisher credential', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://other.test/'])]);
    $transport = new HttpDefinitionTransport(app(Factory::class), 'https://billing.test/api/v1', 'secret');
    expect(fn () => $transport->sync(DefinitionManifest::fromJson(definitionDocument())))->toThrow(BillingUnavailable::class);
    Http::assertSentCount(1);
});

it('refuses unconfigured publication without making a network call', function (): void {
    Http::fake();
    expect(fn () => (new HttpDefinitionTransport(app(Factory::class), 'https://billing.test/api/v1', ''))->sync(DefinitionManifest::fromJson(definitionDocument())))->toThrow(BillingRefused::class);
    Http::assertNothingSent();
});

it('refuses assignments and duplicate keys before publication', function (string $json): void {
    expect(fn () => DefinitionManifest::fromJson($json))->toThrow(InvalidArgumentException::class);
})->with([
    '{"version":"1","features":[],"meters":[],"plans":[]}',
    '{"version":"1","features":[{"key":"sites.domains","name":"Domains","enabled":true}],"meters":[]}',
    '{"version":"1","features":[{"key":"sites.domains","name":"Domains"},{"key":"sites.domains","name":"Other"}],"meters":[]}',
]);

it('makes deployment fail if its manifest is absent', function (): void {
    $fake = new FakeDefinitionTransport;
    app()->instance(DefinitionTransport::class, $fake);
    $this->artisan('billing:sync-definitions', ['file' => '/not-present/definitions.json'])->assertFailed();
    expect($fake->publications)->toBe([]);
});

it('publishes the deploy file and reports failures with a nonzero exit status', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'billing-definitions-');
    file_put_contents($path, definitionDocument());
    try {
        $fake = new FakeDefinitionTransport;
        app()->instance(DefinitionTransport::class, $fake);
        $this->artisan('billing:sync-definitions', ['file' => $path])->assertSuccessful();
        expect($fake->publications)->toHaveCount(1);
        $fake->refusal = new BillingRefused('Publication refused.');
        $this->artisan('billing:sync-definitions', ['file' => $path])->assertFailed();
        expect($fake->publications)->toHaveCount(1);
    } finally {
        unlink($path);
    }
});

it('validates the committed definition file in CI without credentials or publication', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'billing-definitions-');
    file_put_contents($path, definitionDocument());
    try {
        $fake = new FakeDefinitionTransport;
        app()->instance(DefinitionTransport::class, $fake);
        $this->artisan('billing:sync-definitions', ['file' => $path, '--validate-only' => true])->assertSuccessful();
        expect($fake->publications)->toBe([]);
        file_put_contents($path, '{"plans":[]}');
        $this->artisan('billing:sync-definitions', ['file' => $path, '--validate-only' => true])->assertFailed();
        expect($fake->publications)->toBe([]);
    } finally {
        unlink($path);
    }
});
