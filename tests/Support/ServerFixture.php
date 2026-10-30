<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Tests\Support;

use RuntimeException;

/**
 * A recorded server response from `tests/Fixtures/server/*.json`, each taken from the Cbox
 * Billing controller named in its `source`. The fixtures are the one place the server's
 * wire shapes are written down: the real transports parse them, the fakes must answer in
 * the same shape, and the contract suite holds a live server to them.
 */
final readonly class ServerFixture
{
    /** Keys whose children are keyed by data (meter and feature keys), not by field names. */
    private const array MAPS = ['meters', 'entitlements', 'features'];

    /**
     * @param  array<string, mixed>  $body
     */
    private function __construct(
        public string $name,
        public int $status,
        public array $body,
    ) {}

    public static function load(string $name): self
    {
        $path = dirname(__DIR__).'/Fixtures/server/'.$name.'.json';
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Missing server fixture [{$name}].");
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! is_int($decoded['status'] ?? null) || ! is_array($decoded['body'] ?? null)) {
            throw new RuntimeException("Malformed server fixture [{$name}].");
        }

        /** @var array<string, mixed> $body */
        $body = $decoded['body'];

        return new self($name, $decoded['status'], $body);
    }

    /**
     * The key structure of a JSON value: objects become sorted key maps, lists the shape of
     * their first element, data-keyed maps (meters) the shape of their first value, and
     * scalars and null a bare marker. Two responses with the same shape carry the same fields.
     */
    public static function shape(mixed $value, ?string $key = null): mixed
    {
        if (! is_array($value)) {
            return '*';
        }

        if ($value === []) {
            return [];
        }

        if (array_is_list($value)) {
            return [self::shape($value[0])];
        }

        if ($key !== null && in_array($key, self::MAPS, true)) {
            return ['<key>' => self::shape(reset($value))];
        }

        $shape = [];

        foreach ($value as $child => $item) {
            $shape[(string) $child] = self::shape($item, (string) $child);
        }

        ksort($shape);

        return $shape;
    }

    /**
     * The fixture's field paths (`a.b[].c`) that `$live` does not carry. A live server may
     * ADD fields, so only missing ones count. A path below a value the live response has as
     * null or empty (no plans in a fresh sandbox, say) cannot be checked and is not reported.
     *
     * @return list<string>
     */
    public function missingFrom(mixed $live): array
    {
        $want = self::walk(self::shape($this->body), '');
        $have = self::walk(self::shape($live), '');

        $missing = [];

        foreach (array_keys($want) as $path) {
            if (array_key_exists($path, $have) || self::belowLeaf($path, $have)) {
                continue;
            }

            $missing[] = $path;
        }

        return $missing;
    }

    /**
     * @param  array<string, bool>  $have
     */
    private static function belowLeaf(string $path, array $have): bool
    {
        foreach ($have as $candidate => $isLeaf) {
            if ($isLeaf && (str_starts_with($path, $candidate.'.') || str_starts_with($path, $candidate.'[]'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every path in a shape, mapped to whether it is a leaf (a scalar, null or empty value).
     *
     * @return array<string, bool>
     */
    private static function walk(mixed $shape, string $prefix): array
    {
        if (! is_array($shape)) {
            return [];
        }

        $paths = [];

        foreach ($shape as $key => $child) {
            $path = is_int($key) ? $prefix.'[]' : ($prefix === '' ? $key : $prefix.'.'.$key);
            $paths[$path] = ! is_array($child) || $child === [];
            $paths += self::walk($child, $path);
        }

        return $paths;
    }
}
