<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Contracts\ServiceAccessTransport;
use Cbox\Billing\Client\Http\HttpServiceAccessTransport;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/**
 * An in-memory Billing for the service-admission check. Script who is let in with
 * {@see allow()} and {@see refuse()}; everyone else is refused with `not_admitted`. The fake
 * answers over the real {@see HttpServiceAccessTransport}, so parsing and error classification
 * are production's. {@see takeDown()} simulates an outage, {@see refuseWith()} a status, and
 * {@see checks()} counts the questions that reached Billing.
 *
 *     $this->app->instance(ServiceAccessTransport::class, $fake = new FakeServiceAccessTransport);
 *     $fake->allow('usr_1', 'org_1');
 */
class FakeServiceAccessTransport implements ServiceAccessTransport
{
    private const string BASE_URL = 'https://billing.fake';

    /** @var array<string, AccessDecision> */
    private array $decisions = [];

    private bool $down = false;

    private ?int $refusal = null;

    private int $checks = 0;

    private ?HttpServiceAccessTransport $wire = null;

    public function allow(string $subject, ?string $organization = null): self
    {
        $this->decisions[$this->key($subject, $organization)] = new AccessDecision(true);

        return $this;
    }

    public function refuse(string $subject, ?string $organization = null, string $reason = 'waitlisted'): self
    {
        $this->decisions[$this->key($subject, $organization)] = new AccessDecision(false, $reason);

        return $this;
    }

    /** Every subsequent check fails to connect until {@see bringUp()}. */
    public function takeDown(): self
    {
        $this->down = true;

        return $this;
    }

    /** Answer every subsequent check with `$status` until {@see bringUp()}. */
    public function refuseWith(int $status): self
    {
        $this->refusal = $status;

        return $this;
    }

    public function bringUp(): self
    {
        $this->down = false;
        $this->refusal = null;

        return $this;
    }

    /** How many checks reached Billing (or tried to). */
    public function checks(): int
    {
        return $this->checks;
    }

    public function check(string $service, string $subject, ?string $organization = null): AccessDecision
    {
        return $this->wire()->check($service, $subject, $organization);
    }

    /**
     * The server half: `ServiceAdmissionController::check`'s `{allowed, reason, program}`.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function respond(array $payload): FakeResponse
    {
        if ($this->refusal !== null) {
            return FakeResponse::error($this->refusal, 'Refused by the test.');
        }

        $subject = is_string($payload['subject'] ?? null) ? $payload['subject'] : '';
        $organization = is_string($payload['organization'] ?? null) ? $payload['organization'] : null;
        $decision = $this->decisions[$this->key($subject, $organization)] ?? new AccessDecision(false, 'not_admitted');

        return FakeResponse::json($decision->toArray() + ['program' => null]);
    }

    private function wire(): HttpServiceAccessTransport
    {
        return $this->wire ??= new HttpServiceAccessTransport($this->server(), self::BASE_URL, 'fake-access-token');
    }

    private function server(): HttpFactory
    {
        return (new HttpFactory)->fake(function (Request $request) {
            $this->checks++;

            if ($this->down) {
                throw new ConnectionException('cURL error 7: billing is down (test outage)');
            }

            return $this->respond($request->data())->toPromise();
        });
    }

    private function key(string $subject, ?string $organization): string
    {
        return $subject."\0".($organization ?? '');
    }
}
