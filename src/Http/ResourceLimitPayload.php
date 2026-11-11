<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\LimitIncrease;
use Cbox\Billing\Client\ValueObjects\LimitIncreasePage;
use Cbox\Billing\Client\ValueObjects\ResourceLimit;
use DateTimeImmutable;
use Throwable;

/** Validates the wire boundary; missing or inconsistent limits never become unlimited. */
class ResourceLimitPayload
{
    /** @param array<string, mixed> $body
     * @return array<string, ResourceLimit>
     */
    public static function limits(array $body, string $path): array
    {
        if (($body['scope'] ?? null) !== 'organization' || ($body['monetary_exposure_included'] ?? null) !== false || ! is_array($body['limits'] ?? null)) {
            throw TransportException::malformed($path, 'missing resource limit scope or limits');
        }
        $limits = [];
        foreach ($body['limits'] as $key => $row) {
            if (! is_string($key) || $key === '' || ! is_array($row) || ($row['key'] ?? null) !== $key || ! is_bool($row['enabled'] ?? null)) {
                throw TransportException::malformed($path, 'invalid resource limit entry');
            }
            $limit = self::integer($row['limit'] ?? null, $path);
            $base = self::integer($row['base_limit'] ?? null, $path);
            $grant = self::nullableString($row, 'grant_request_id', $path);
            $source = self::string($row['source'] ?? null, $path);
            $expires = self::date($row, 'expires_at', $path, true);
            $observed = self::date($row, 'observed_at', $path);
            if (! in_array($source, ['catalog', 'approved_request'], true) || (! $row['enabled'] && $limit !== 0)
                || ($source === 'approved_request' && ($grant === null || $limit <= $base))
                || ($source === 'catalog' && ($grant !== null || $expires !== null || $limit !== $base))
                || ($expires !== null && $expires <= $observed)) {
                throw TransportException::malformed($path, 'inconsistent resource ceiling');
            }
            $limits[$key] = new ResourceLimit($key, $row['enabled'], $limit, $base, $grant, $expires, $source, $observed);
        }

        return $limits;
    }

    /** @param array<string, mixed> $body */
    public static function page(array $body, string $path): LimitIncreasePage
    {
        if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw TransportException::malformed($path, 'missing request list');
        }
        $requests = [];
        foreach ($body['data'] as $row) {
            if (! is_array($row)) {
                throw TransportException::malformed($path, 'invalid request entry');
            }
            $requests[] = self::request($row, $path);
        }
        $current = self::integer($body['current_page'] ?? null, $path);
        $last = self::integer($body['last_page'] ?? null, $path);
        if ($current < 1 || $last < 1) {
            throw TransportException::malformed($path, 'invalid pagination');
        }

        return new LimitIncreasePage($requests, $current, $last);
    }

    /** @param array<array-key, mixed> $row */
    public static function request(array $row, string $path): LimitIncrease
    {
        $id = self::string($row['id'] ?? null, $path);
        $status = self::string($row['status'] ?? null, $path);
        $requested = self::integer($row['requested_limit'] ?? null, $path);
        $approved = self::nullableInteger($row, 'approved_limit', $path);
        $decided = self::date($row, 'decided_at', $path, true);
        $key = self::string($row['request_key'] ?? null, $path);
        if ($requested < 1 || ! in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $key)
            || ($status === 'approved' && ($approved === null || $approved < 1 || $approved > $requested || $decided === null))
            || ($status !== 'approved' && $approved !== null)) {
            throw TransportException::malformed($path, 'inconsistent limit request');
        }

        return new LimitIncrease($id, $key, self::string($row['feature'] ?? null, $path), $status,
            self::integer($row['observed_limit'] ?? null, $path), $requested, self::string($row['reason'] ?? null, $path),
            $approved, self::nullableString($row, 'decision_reason', $path), $decided,
            self::date($row, 'expires_at', $path, true), self::date($row, 'revoked_at', $path, true),
            self::nullableString($row, 'revocation_reason', $path), self::date($row, 'created_at', $path));
    }

    private static function integer(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 0 || $value > 9007199254740991) {
            throw TransportException::malformed($path, 'expected a nonnegative integer');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private static function nullableInteger(array $row, string $key, string $path): ?int
    {
        if (! array_key_exists($key, $row)) {
            throw TransportException::malformed($path, 'missing '.$key);
        }

        return $row[$key] === null ? null : self::integer($row[$key], $path);
    }

    private static function string(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw TransportException::malformed($path, 'expected a non-empty string');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private static function nullableString(array $row, string $key, string $path): ?string
    {
        if (! array_key_exists($key, $row)) {
            throw TransportException::malformed($path, 'missing '.$key);
        }

        return $row[$key] === null ? null : self::string($row[$key], $path);
    }

    /** @param array<array-key, mixed> $row */
    private static function date(array $row, string $key, string $path, bool $nullable = false): ?DateTimeImmutable
    {
        if (array_key_exists($key, $row) && $row[$key] === null && $nullable) {
            return null;
        }
        $raw = self::string($row[$key] ?? null, $path);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $raw)) {
            throw TransportException::malformed($path, 'invalid '.$key);
        }
        try {
            $date = new DateTimeImmutable($raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw TransportException::malformed($path, 'invalid '.$key);
            }

            return $date;
        } catch (Throwable) {
            throw TransportException::malformed($path, 'invalid '.$key);
        }
    }
}
