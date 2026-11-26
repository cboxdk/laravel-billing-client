<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Contracts\SponsoredCapacityTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\CapacityAllocation;
use Cbox\Billing\Client\ValueObjects\CapacityClaim;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/** No automatic retries, redirects, cached grants, lease TTLs or fail-open policy. */
readonly class HttpSponsoredCapacityTransport implements SponsoredCapacityTransport
{
    public function __construct(private Factory $http, private string $baseUrl, private string $token, private int $timeout = 5, private int $connectTimeout = 2) {}

    public function reserve(string $agreement, string $claim, string $org, string $allocation, int $amount): CapacityClaim
    {
        self::key($allocation);
        self::amount($amount);
        if (trim($org) === '' || mb_strlen($org) > 191) {
            throw new InvalidArgumentException('A customer organization is required.');
        }
        $result = $this->readClaim('PUT', $agreement, $claim, '', compact('org', 'allocation', 'amount'));
        // The server resolves external org references, so its canonical org may differ.
        if ($result->allocation->key !== $allocation || $result->allocation->estimate !== $amount) {
            throw TransportException::malformed($this->path($agreement, 'claims', $claim), 'reservation does not match the request');
        }

        return $result;
    }

    public function claim(string $agreement, string $claim): CapacityClaim
    {
        return $this->readClaim('GET', $agreement, $claim);
    }

    public function commit(string $agreement, string $claim, int $actual): CapacityClaim
    {
        self::amount($actual, allowZero: true);
        $result = $this->readClaim('POST', $agreement, $claim, '/commit', compact('actual'));
        if ($result->state !== 'committed' || $result->allocation->actual !== $actual) {
            throw TransportException::malformed($this->path($agreement, 'claims', $claim), 'settlement does not match the request');
        }

        return $result;
    }

    public function cancel(string $agreement, string $claim): CapacityClaim
    {
        $result = $this->readClaim('POST', $agreement, $claim, '/cancel');
        if ($result->state !== 'canceled') {
            throw TransportException::malformed($this->path($agreement, 'claims', $claim), 'claim was not canceled');
        }

        return $result;
    }

    public function allocation(string $agreement, string $allocation): CapacityAllocation
    {
        return $this->readAllocation('GET', $agreement, $allocation);
    }

    public function release(string $agreement, string $allocation, string $deletion): CapacityAllocation
    {
        self::key($deletion);
        $result = $this->readAllocation('POST', $agreement, $allocation, '/release', compact('deletion'));
        if ($result->state !== 'released' || $result->deletion !== $deletion) {
            throw TransportException::malformed($this->path($agreement, 'allocations', $allocation), 'deletion does not match the request');
        }

        return $result;
    }

    /** @param array<string, int|string> $body */
    private function readClaim(string $method, string $agreement, string $claim, string $suffix = '', array $body = []): CapacityClaim
    {
        $path = $this->path($agreement, 'claims', $claim).$suffix;
        try {
            $result = CapacityClaim::fromArray($this->request($method, $path, $body));
        } catch (InvalidArgumentException) {
            throw TransportException::malformed($path, 'invalid claim');
        }
        if ($result->agreement !== $agreement || $result->key !== $claim) {
            throw TransportException::malformed($path, 'wrong claim identity');
        }

        return $result;
    }

    /** @param array<string, int|string> $body */
    private function readAllocation(string $method, string $agreement, string $allocation, string $suffix = '', array $body = []): CapacityAllocation
    {
        $path = $this->path($agreement, 'allocations', $allocation).$suffix;
        try {
            $result = CapacityAllocation::fromArray($this->request($method, $path, $body));
        } catch (InvalidArgumentException) {
            throw TransportException::malformed($path, 'invalid allocation');
        }
        if ($result->agreement !== $agreement || $result->key !== $allocation) {
            throw TransportException::malformed($path, 'wrong allocation identity');
        }

        return $result;
    }

    /**
     * @param  array<string, int|string>  $body
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if ($this->token === '' || ! filter_var($this->baseUrl, FILTER_VALIDATE_URL)
            || ($scheme !== 'https' && ! ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))
            || parse_url($this->baseUrl, PHP_URL_USER) !== null || parse_url($this->baseUrl, PHP_URL_QUERY) !== null
            || parse_url($this->baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new BillingRefused('Sponsored capacity requires a Billing API URL and provider-bound token.');
        }
        try {
            $response = $this->http->acceptJson()->withToken($this->token)->withoutRedirecting()
                ->connectTimeout(max(1, min($this->connectTimeout, $this->timeout)))->timeout(max(1, $this->timeout))
                ->send($method, rtrim($this->baseUrl, '/').$path, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $exception) {
            throw TransportException::unreachable($path, $exception);
        }
        if (! $response->successful()) {
            throw TransportException::fromResponse($path, $response);
        }
        $data = $response->json();
        if ($response->status() !== 200 || ! is_array($data)) {
            throw TransportException::malformed($path, 'expected a 200 object response');
        }

        return $data;
    }

    private function path(string $agreement, string $kind, string $key): string
    {
        self::key($agreement);
        self::key($key);

        return '/capacity-agreements/'.$agreement.'/'.$kind.'/'.$key;
    }

    private static function key(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Capacity keys require 1–128 letters, digits, underscores or hyphens.');
        }
    }

    private static function amount(int $amount, bool $allowZero = false): void
    {
        if ($amount < ($allowZero ? 0 : 1) || $amount > CapacityAllocation::MAX_AMOUNT) {
            throw new InvalidArgumentException('Capacity amount is outside the safe integer range.');
        }
    }
}
