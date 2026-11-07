<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Definitions;

use InvalidArgumentException;

/** A complete definition document. It cannot contain plans, prices, or assignments. */
final readonly class DefinitionManifest
{
    /** @param list<array<string, mixed>> $features
     * @param  list<array<string, mixed>>  $meters
     */
    private function __construct(public string $version, public array $features, public array $meters) {}

    public static function fromJson(string $json): self
    {
        $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($document) || array_diff(array_keys($document), ['version', 'features', 'meters']) !== []) {
            throw new InvalidArgumentException('A definition manifest contains only version, features, and meters.');
        }
        $version = $document['version'] ?? null;
        if (! is_string($version) || trim($version) === '' || strlen($version) > 128) {
            throw new InvalidArgumentException('A non-empty definition version of at most 128 characters is required.');
        }

        return new self($version,
            self::definitions($document['features'] ?? null, ['key', 'name', 'description', 'type', 'value_type']),
            self::definitions($document['meters'] ?? null, ['key', 'name', 'unit', 'aggregation', 'source', 'time_unit', 'filters', 'group_by', 'per', 'every']),
        );
    }

    /** @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private static function definitions(mixed $value, array $fields): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 500) {
            throw new InvalidArgumentException('Features and meters must each be a list of at most 500 definitions.');
        }
        $rows = [];
        $seen = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Every definition must be an object.');
            }
            $out = [];
            foreach ($row as $field => $fieldValue) {
                if (! is_string($field) || ! in_array($field, $fields, true)) {
                    throw new InvalidArgumentException('An unsupported field was supplied in a definition.');
                }
                $out[$field] = $fieldValue;
            }
            $key = $out['key'] ?? null;
            $name = $out['name'] ?? null;
            if (! is_string($key) || strlen($key) > 80 || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $key) !== 1
                || isset($seen[$key]) || ! is_string($name) || trim($name) === '' || strlen($name) > 120) {
                throw new InvalidArgumentException('Definitions need unique keys and non-empty names.');
            }
            $seen[$key] = true;
            $rows[] = $out;
        }

        return $rows;
    }

    /** @return array{version: string, features: list<array<string, mixed>>, meters: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'features' => $this->features, 'meters' => $this->meters];
    }
}
