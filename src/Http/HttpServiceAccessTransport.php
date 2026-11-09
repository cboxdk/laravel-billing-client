<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Exceptions\BillingRefused;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Asks `POST /api/v1/access/services/{service}/check` with a product-bound token carrying the
 * `access:enroll` scope. The base URL must be https (http only for localhost), without
 * credentials, query or fragment; an unconfigured or unsafe target is refused before any
 * request is made. The answer must carry a boolean `allowed`; anything else is malformed.
 */
class HttpServiceAccessTransport implements ServiceAccessTransport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 5,
        private readonly int $connectTimeout = 2,
    ) {}

    public function check(string $service, string $subject, ?string $organization = null): AccessDecision
    {
        $path = '/api/v1/access/services/'.rawurlencode($service).'/check';

        if ($service === '' || $subject === '' || ! $this->safeTarget()) {
            throw new BillingRefused('Service access needs a service key, a subject, an https Billing URL and an access token.', $path);
        }

        try {
            $response = $this->http->asJson()->acceptJson()->withToken($this->token)->withoutRedirecting()
                ->connectTimeout(max(1, min($this->connectTimeout, $this->timeout)))->timeout(max(1, $this->timeout))
                ->post(rtrim($this->baseUrl, '/').$path, array_filter(
                    ['subject' => $subject, 'organization' => $organization],
                    static fn (?string $value): bool => $value !== null && $value !== '',
                ));
        } catch (ConnectionException $e) {
            throw TransportException::unreachable($path, $e);
        }

        if (! $response->successful()) {
            throw TransportException::fromResponse($path, $response);
        }

        $decision = AccessDecision::fromArray($response->json());

        if ($decision === null) {
            throw TransportException::malformed($path, 'missing boolean field [allowed]');
        }

        return $decision;
    }

    private function safeTarget(): bool
    {
        $parts = parse_url($this->baseUrl);

        if ($this->token === '' || ! is_array($parts) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = $parts['scheme'] ?? '';

        return $scheme === 'https' || ($scheme === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
