<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Access;

use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Exceptions\BillingClientException;
use Cbox\Billing\Client\ValueObjects\AccessDecision;
use InvalidArgumentException;

/**
 * May this person use this service? The per-page admission check, answered from a kept answer
 * ({@see KeptAnswers}) so that pages render while Billing is away:
 *
 *     $access->allows($user->id, $organization->id);              // a page
 *     $access->allows($user->id, $organization->id, acting: true); // a change that must be current
 *
 * A safe request is answered at once from the kept answer, and a stale one is asked again in the
 * background. A change, `acting: true`, and work outside a request use only a fresh answer. With
 * nothing kept the request waits for Billing once, and a Billing failure throws as before.
 *
 * When the app itself admitted someone, or a webhook said their access changed, call
 * {@see remember()} or {@see forget()} so the next page does not show the old answer.
 */
class ServiceAccess
{
    public function __construct(
        private readonly KeptAnswers $answers,
        private readonly string $service = '',
    ) {}

    /** The same check for another service key than `billing-client.service_access.service`. */
    public function forService(string $service): self
    {
        return new self($this->answers, $service);
    }

    /**
     * @throws BillingClientException when Billing must be asked and cannot answer
     */
    public function allows(string $subject, ?string $organization = null, bool $acting = false): bool
    {
        return $this->decision($subject, $organization, $acting)->allowed;
    }

    /**
     * The kept decision, with Billing's reason when it says no.
     *
     * @throws BillingClientException when Billing must be asked and cannot answer
     */
    public function decision(string $subject, ?string $organization = null, bool $acting = false): AccessDecision
    {
        return AccessDecision::fromArray($this->answers->answer($this->question($subject, $organization), $acting)) ?? new AccessDecision;
    }

    /**
     * Ask Billing now and keep the answer.
     *
     * @throws BillingClientException when Billing cannot answer
     */
    public function check(string $subject, ?string $organization = null): AccessDecision
    {
        return AccessDecision::fromArray($this->answers->refresh($this->question($subject, $organization))) ?? new AccessDecision;
    }

    /** Keep a decision the app already knows (it just admitted the person, or a webhook said so). */
    public function remember(string $subject, ?string $organization, AccessDecision|bool $decision): void
    {
        $decision = is_bool($decision) ? new AccessDecision($decision) : $decision;

        $this->answers->keep($this->question($subject, $organization), $decision->toArray());
    }

    /** Drop the kept decision: the next check asks Billing. */
    public function forget(string $subject, ?string $organization = null): void
    {
        $this->answers->forget($this->question($subject, $organization));
    }

    public function service(): string
    {
        return $this->service;
    }

    private function question(string $subject, ?string $organization): ServiceAccessQuestion
    {
        if ($this->service === '') {
            throw new InvalidArgumentException('Set billing-client.service_access.service (BILLING_CLIENT_SERVICE) or call forService().');
        }

        return new ServiceAccessQuestion($this->service, $subject, $organization === '' ? null : $organization);
    }
}
