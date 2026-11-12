<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Contracts\OrganizationQuestion;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\SubscriptionPayload;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Illuminate\Contracts\Container\Container;

/**
 * "What is this organization's subscription?" as a kept question. The answer is
 * `['subscription' => <wire shape>|null]`: null is Billing's answer for an organization with no
 * serving subscription, and is kept like any other answer.
 */
readonly class SubscriptionQuestion implements OrganizationQuestion
{
    public function __construct(public string $organization) {}

    public function key(): string
    {
        return 'subscription:'.$this->organization;
    }

    public function organization(): string
    {
        return $this->organization;
    }

    /** @return array{subscription: array<string, mixed>|null} */
    public function ask(Container $app): array
    {
        $subscription = $app->make(ManagementTransport::class)->subscription($this->organization);

        return self::wrap($subscription);
    }

    public function accepts(mixed $value): bool
    {
        if (! is_array($value) || ! array_key_exists('subscription', $value)) {
            return false;
        }

        if ($value['subscription'] === null) {
            return true;
        }

        return self::parse($value) instanceof Subscription;
    }

    /** @return array{subscription: array<string, mixed>|null} */
    public static function wrap(?Subscription $subscription): array
    {
        return ['subscription' => $subscription === null ? null : SubscriptionPayload::toArray($subscription)];
    }

    /** The subscription a kept answer holds, or null (no subscription, or not a valid answer). */
    public static function parse(mixed $value): ?Subscription
    {
        if (! is_array($value) || ! is_array($value['subscription'] ?? null)) {
            return null;
        }

        try {
            return SubscriptionPayload::subscription($value['subscription'], 'kept subscription');
        } catch (TransportException) {
            return null;
        }
    }
}
