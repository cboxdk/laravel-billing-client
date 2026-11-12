<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Contracts\OrganizationQuestion;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * What Billing said changed, so that a kept answer ({@see KeptAnswers}) from before the change
 * is not served. Call it from the app's Billing webhook, after the signature is verified:
 *
 *     $changes->fromWebhook($event['type'], $event['data']);
 *
 * or directly: {@see organization()} when one organization's subscription, standing or access
 * changed, {@see everything()} when a plan did.
 *
 * Nothing is deleted. A mark is the time of the change; an answer about that organization
 * ({@see OrganizationQuestion}) asked at or before it is asked again on its next use, on a page
 * too. If Billing cannot answer then, a page is still served the kept answer.
 */
class KeptChanges
{
    /** Event types that can change what an organization may use. */
    private const array ORGANIZATION_EVENTS = ['subscription.', 'customer.', 'access.'];

    /** Event types that change what every organization on a plan gets. */
    private const array CATALOG_EVENTS = ['plan.'];

    /** Bumped on every mark made by this process, so a request that marked reads its own mark. */
    private int $generation = 0;

    public function __construct(
        private readonly Cache $cache,
        private readonly int $keepFor = 5_184_000,
        private readonly string $prefix = 'cbox-billing-client:changed:',
    ) {}

    /** Billing said this organization's subscription, standing or access changed. */
    public function organization(string $organization): void
    {
        if ($organization !== '') {
            $this->mark($this->organizationKey($organization));
        }
    }

    /** Billing said something changed for everyone (a plan's features or limits). */
    public function everything(): void
    {
        $this->mark($this->prefix.'all');
    }

    /**
     * Mark what a verified Billing webhook says changed. `subscription.*`, `customer.*` and
     * `access.*` name an organization in `organization_ref`; `plan.*` concerns everyone. Any
     * other event changes no kept answer. Returns whether something was marked.
     *
     * @param  array<array-key, mixed>  $payload  the event's own fields (its `data`)
     */
    public function fromWebhook(string $type, array $payload): bool
    {
        if (self::startsWithAny($type, self::CATALOG_EVENTS)) {
            $this->everything();

            return true;
        }

        $organization = $payload['organization_ref'] ?? null;

        if (! self::startsWithAny($type, self::ORGANIZATION_EVENTS) || ! is_string($organization) || $organization === '') {
            return false;
        }

        $this->organization($organization);

        return true;
    }

    /**
     * The cache keys whose marks concern an answer about `$organization`.
     *
     * @return list<string>
     */
    public function keys(?string $organization): array
    {
        return $organization === null || $organization === ''
            ? [$this->prefix.'all']
            : [$this->prefix.'all', $this->organizationKey($organization)];
    }

    /** Changes whenever this process marked something. */
    public function generation(): int
    {
        return $this->generation;
    }

    private function mark(string $key): void
    {
        $this->cache->put($key, Carbon::now()->getTimestamp(), max(1, $this->keepFor));
        $this->generation++;
    }

    private function organizationKey(string $organization): string
    {
        return $this->prefix.'org:'.hash('sha256', $organization);
    }

    /** @param  list<string>  $prefixes */
    private static function startsWithAny(string $type, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
