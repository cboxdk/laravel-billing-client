<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Exceptions\BillingClientException;
use Cbox\Billing\Client\ValueObjects\Subscription;

/**
 * An organization's subscription, read from a kept answer ({@see KeptAnswers}) so that a page
 * that needs the plan, its limits or its status never waits on Billing:
 *
 *     $subscription = $kept->subscription($organization->cbox_id);              // a page
 *     $subscription = $kept->subscription($organization->cbox_id, acting: true); // before a change
 *
 * Same answer as {@see BillingManagement::subscription()}: a {@see Subscription}, or null when
 * Billing says the organization has none. The kept-answer rules decide when Billing is asked.
 * When the `plan.changed` webhook (or the app's own subscribe, change or cancel) tells the app
 * the subscription changed, call {@see forget()} or {@see remember()}.
 */
class KeptSubscriptions
{
    public function __construct(private readonly KeptAnswers $answers) {}

    /**
     * @throws BillingClientException when Billing must be asked and cannot answer
     */
    public function subscription(string $organization, bool $acting = false): ?Subscription
    {
        return SubscriptionQuestion::parse($this->answers->answer(new SubscriptionQuestion($organization), $acting));
    }

    /**
     * Ask Billing now and keep the answer.
     *
     * @throws BillingClientException when Billing cannot answer
     */
    public function refresh(string $organization): ?Subscription
    {
        return SubscriptionQuestion::parse($this->answers->refresh(new SubscriptionQuestion($organization)));
    }

    /** Keep a subscription the app already holds (the answer to its own subscribe or change). */
    public function remember(string $organization, ?Subscription $subscription): void
    {
        $this->answers->keep(new SubscriptionQuestion($organization), SubscriptionQuestion::wrap($subscription));
    }

    /** Drop the kept subscription: the next read asks Billing. */
    public function forget(string $organization): void
    {
        $this->answers->forget(new SubscriptionQuestion($organization));
    }
}
