<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * One answer from the in-memory billing server inside {@see FakeBillingTransport} and
 * {@see FakeManagementTransport}: the HTTP status, the decoded JSON body (null for an
 * empty 204), and any headers. The fakes hand it to the real HTTP transport, so a test
 * exercises exactly the parsing and error classification production does.
 */
readonly class FakeResponse
{
    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public ?array $body,
        public array $headers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    /** The `{error: ...}` body the server's controllers and token middleware send. */
    public static function error(int $status, string $message): self
    {
        $headers = $status === 429 ? ['Retry-After' => '60'] : [];

        return new self($status, ['error' => $message], $headers);
    }

    /** The body Laravel's validator sends with a 422. */
    public static function validation(string $field, string $message): self
    {
        return new self(422, ['message' => $message, 'errors' => [$field => [$message]]]);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public function toPromise(): PromiseInterface
    {
        return HttpFactory::response($this->body, $this->status, $this->headers);
    }
}
