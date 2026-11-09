<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Contracts\DefinitionTransport;
use Cbox\Billing\Client\Definitions\DefinitionManifest;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Illuminate\Http\Client\Factory;
use Throwable;

final readonly class HttpDefinitionTransport implements DefinitionTransport
{
    public function __construct(private Factory $http, private string $baseUrl, private string $token, private int $timeout = 10, private int $connectTimeout = 2) {}

    public function sync(DefinitionManifest $manifest): void
    {
        $url = rtrim($this->baseUrl, '/').'/api/v1/definitions';
        $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if ($this->baseUrl === '' || $this->token === '' || ! filter_var($this->baseUrl, FILTER_VALIDATE_URL)
            || ($scheme !== 'https' && ! ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))
            || parse_url($this->baseUrl, PHP_URL_USER) !== null || parse_url($this->baseUrl, PHP_URL_QUERY) !== null
            || parse_url($this->baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new BillingRefused('Definition synchronization requires a Billing URL and product-bound definition token.');
        }
        $body = $manifest->toArray();
        try {
            $response = $this->http->acceptJson()->withToken($this->token)->withoutRedirecting()
                ->connectTimeout(max(1, min($this->connectTimeout, $this->timeout)))->timeout(max(1, $this->timeout))
                ->put($url, $body);
        } catch (Throwable) {
            throw new BillingUnavailable('Definition synchronization could not reach Billing.');
        }
        if (! $response->successful()) {
            throw TransportException::fromResponse('/api/v1/definitions', $response);
        }
        $rows = $response->json('data');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw TransportException::malformed('/api/v1/definitions', 'missing definition list');
        }
        $actual = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['kind'] ?? null) || ! is_string($row['key'] ?? null)
                || ! array_key_exists('retired_at', $row)) {
                throw TransportException::malformed('/api/v1/definitions', 'invalid definition acknowledgement');
            }
            if ($row['retired_at'] === null) {
                if (($row['version'] ?? null) !== $manifest->version) {
                    throw TransportException::malformed('/api/v1/definitions', 'wrong definition version');
                }
                $actual[] = $row['kind'].':'.$row['key'];
            }
        }
        $expected = [];
        foreach (['feature' => $manifest->features, 'meter' => $manifest->meters] as $kind => $definitions) {
            foreach ($definitions as $definition) {
                $key = $definition['key'];
                if (is_string($key)) {
                    $expected[] = $kind.':'.$key;
                }
            }
        }
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw TransportException::malformed('/api/v1/definitions', 'Billing did not acknowledge the complete manifest');
        }
    }
}
