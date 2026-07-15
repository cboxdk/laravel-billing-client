<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Stores;

use Cbox\Billing\Client\Contracts\ReservationRegistry;
use Cbox\Billing\Client\ValueObjects\PendingReservation;
use Illuminate\Contracts\Cache\Repository;

/**
 * {@see ReservationRegistry} backed by Laravel's cache — the default durable register
 * of held reservations. Each open hold is stored as its own key (the org, per-meter
 * lease-backed amounts, and TTL), tracked by a small index key so the sweeper can
 * enumerate holds without a key scan. Point it at a persistent cache in production so
 * a pending hold survives a process restart until it is swept.
 *
 * Unlike the lease counter this register may `put`/`forget` freely: it holds
 * reservation RECORDS, not a spend counter, so there is no double-spend invariant to
 * protect — a lost record simply means its (already-central) lease is reclaimed a
 * period later, never over-granted.
 */
class CacheReservationRegistry implements ReservationRegistry
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'cbox-billing-client:pending:',
    ) {}

    public function open(string $id, string $org, array $holds, int $expiresAt): void
    {
        if ($holds === [] || $id === '') {
            return;
        }

        $this->cache->forever($this->recordKey($id), $this->encode($org, $holds, $expiresAt));
        $this->remember($id);
    }

    public function close(string $id): void
    {
        $this->cache->forget($this->recordKey($id));
        $this->forgetIndex($id);
    }

    public function expired(int $now): array
    {
        $out = [];

        foreach ($this->index() as $id) {
            $record = $this->decode($id);

            if ($record === null) {
                // Record gone but still indexed — drop the dangling index entry.
                $this->forgetIndex($id);

                continue;
            }

            if ($record->expiresAt <= $now) {
                $out[] = $record;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $holds
     */
    private function encode(string $org, array $holds, int $expiresAt): string
    {
        return (string) json_encode([
            'org' => $org,
            'holds' => $holds,
            'expires_at' => $expiresAt,
        ]);
    }

    private function decode(string $id): ?PendingReservation
    {
        $raw = $this->cache->get($this->recordKey($id));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $org = $decoded['org'] ?? null;
        $expiresAt = $decoded['expires_at'] ?? null;
        $rawHolds = $decoded['holds'] ?? null;

        if (! is_string($org) || ! is_int($expiresAt) || ! is_array($rawHolds)) {
            return null;
        }

        $holds = [];

        foreach ($rawHolds as $meter => $amount) {
            if (is_string($meter) && is_int($amount) && $amount > 0) {
                $holds[$meter] = $amount;
            }
        }

        if ($holds === []) {
            return null;
        }

        return new PendingReservation($id, $org, $holds, $expiresAt);
    }

    /**
     * @return list<string>
     */
    private function index(): array
    {
        $raw = $this->cache->get($this->indexKey());

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode("\n", $raw) as $id) {
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function remember(string $id): void
    {
        $ids = $this->index();

        if (in_array($id, $ids, true)) {
            return;
        }

        $ids[] = $id;
        $this->cache->forever($this->indexKey(), implode("\n", $ids));
    }

    private function forgetIndex(string $id): void
    {
        $ids = array_values(array_filter($this->index(), static fn (string $existing): bool => $existing !== $id));

        if ($ids === []) {
            $this->cache->forget($this->indexKey());

            return;
        }

        $this->cache->forever($this->indexKey(), implode("\n", $ids));
    }

    private function recordKey(string $id): string
    {
        return $this->prefix.$id;
    }

    private function indexKey(): string
    {
        return $this->prefix.'index';
    }
}
