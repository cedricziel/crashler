<?php

declare(strict_types=1);

namespace App\Schema;

use Symfony\Component\Yaml\Yaml;

final readonly class SchemaDefinition
{
    public const array ALLOWED_TYPES = ['int32', 'int64', 'string', 'boolean', 'float', 'double', 'dateTime'];
    public const array ALLOWED_REPETITIONS = ['required', 'optional'];
    public const array TRANSFORM_SUBKEYS = ['drop_keys', 'rename_keys', 'defaults', 'redact_keys', 'derive', 'drop_when'];
    public const string RESERVED_COLUMN_PREFIX = '_schema_';

    /**
     * @param list<SchemaColumn>          $columns
     * @param array<string, list<string>> $resourcePromotions column-name → list of fallback semconv keys
     * @param array<string, list<string>> $scopePromotions
     * @param array<string, list<string>> $recordPromotions
     */
    public function __construct(
        public string $signal,
        public int $version,
        public array $columns,
        public array $resourcePromotions,
        public array $scopePromotions,
        public array $recordPromotions,
        public string $yamlSha256 = '',
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, string $yamlSha256 = ''): self
    {
        self::validate($raw);

        $columns = [];
        foreach ($raw['columns'] as $entry) {
            $columns[] = new SchemaColumn(
                name: (string) $entry['name'],
                type: (string) $entry['type'],
                repetition: (string) $entry['repetition'],
            );
        }

        $promotions = $raw['promotions'];

        return new self(
            signal: (string) $raw['signal'],
            version: (int) $raw['version'],
            columns: $columns,
            resourcePromotions: self::normalisePromotions($promotions['resource'] ?? [], $columns),
            scopePromotions: self::normalisePromotions($promotions['scope'] ?? [], $columns),
            recordPromotions: self::normalisePromotions($promotions['record'] ?? [], $columns),
            yamlSha256: $yamlSha256,
        );
    }

    public static function fromYamlString(string $yaml): self
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parse($yaml) ?? [];
        if (!\is_array($parsed)) {
            throw new \InvalidArgumentException('Schema YAML must parse to an associative array.');
        }

        return self::fromArray($parsed, hash('sha256', $yaml));
    }

    public function id(): string
    {
        return $this->signal.'/v'.$this->version;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function validate(array $raw): void
    {
        $columns = $raw['columns'] ?? null;
        if (!\is_array($columns) || [] === $columns) {
            throw new \InvalidArgumentException('Schema "columns" must be a non-empty list.');
        }

        $seenNames = [];
        foreach ($columns as $i => $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(\sprintf('columns[%d] must be a map.', $i));
            }
            $name = $entry['name'] ?? null;
            if (!\is_string($name) || '' === $name) {
                throw new \InvalidArgumentException(\sprintf('columns[%d].name must be a non-empty string.', $i));
            }
            if (str_starts_with($name, self::RESERVED_COLUMN_PREFIX)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Column name "%s" uses the reserved "%s" prefix; the writer emits these universally.',
                    $name,
                    self::RESERVED_COLUMN_PREFIX,
                ));
            }
            if (isset($seenNames[$name])) {
                throw new \InvalidArgumentException(\sprintf('Duplicate column name "%s" in schema.', $name));
            }
            $seenNames[$name] = true;

            $type = $entry['type'] ?? null;
            if (!\is_string($type) || !\in_array($type, self::ALLOWED_TYPES, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Column "%s" has invalid type "%s"; expected one of: %s.',
                    $name,
                    \is_string($type) ? $type : (string) \gettype($type),
                    implode(', ', self::ALLOWED_TYPES),
                ));
            }

            $rep = $entry['repetition'] ?? null;
            if (!\is_string($rep) || !\in_array($rep, self::ALLOWED_REPETITIONS, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Column "%s" has invalid repetition "%s"; expected one of: %s.',
                    $name,
                    \is_string($rep) ? $rep : (string) \gettype($rep),
                    implode(', ', self::ALLOWED_REPETITIONS),
                ));
            }
        }

        $promotions = $raw['promotions'] ?? null;
        if (!\is_array($promotions)) {
            throw new \InvalidArgumentException('Schema "promotions" block must be an object with resource/scope/record sub-maps.');
        }
        foreach (['resource', 'scope', 'record'] as $level) {
            $entries = $promotions[$level] ?? [];
            if (!\is_array($entries)) {
                throw new \InvalidArgumentException(\sprintf('promotions.%s must be a map.', $level));
            }
            // YAML shape: {semconv_key: column_name}.
            foreach ($entries as $semconvKey => $columnName) {
                if (!\is_string($semconvKey) || '' === $semconvKey) {
                    throw new \InvalidArgumentException(\sprintf('promotions.%s contains a non-string key.', $level));
                }
                if (!\is_string($columnName) || !isset($seenNames[$columnName])) {
                    throw new \InvalidArgumentException(\sprintf(
                        'promotions.%s entry "%s" maps to column "%s" which is not declared in "columns".',
                        $level,
                        $semconvKey,
                        \is_string($columnName) ? $columnName : (string) \gettype($columnName),
                    ));
                }
            }
        }

        $transforms = $raw['transforms'] ?? null;
        if (!\is_array($transforms)) {
            throw new \InvalidArgumentException('Schema "transforms" block is required.');
        }
        foreach (self::TRANSFORM_SUBKEYS as $sub) {
            if (!\array_key_exists($sub, $transforms)) {
                throw new \InvalidArgumentException(\sprintf('transforms.%s is required (may be empty).', $sub));
            }
        }
        foreach (self::TRANSFORM_SUBKEYS as $sub) {
            if (!self::isEmptyTransformBlock($transforms[$sub], $sub)) {
                throw new \InvalidArgumentException(\sprintf(
                    'transforms.%s is non-empty; transforms are not yet implemented in this version.',
                    $sub,
                ));
            }
        }
    }

    /**
     * 'defaults' is itself a two-level map ({resource: {}, record: {}}); other
     * sub-keys are flat lists or maps that are empty when their length is 0.
     */
    private static function isEmptyTransformBlock(mixed $value, string $sub): bool
    {
        if ('defaults' === $sub) {
            if (!\is_array($value)) {
                return false;
            }
            $resource = $value['resource'] ?? null;
            $record = $value['record'] ?? null;

            return \is_array($resource) && [] === $resource && \is_array($record) && [] === $record;
        }

        return \is_array($value) && [] === $value;
    }

    /**
     * Invert YAML's {semconv_key: column} into {column: [semconv_keys]} so
     * extraction code can look up by column and iterate keys in the order
     * they were declared (legacy fallback semantics).
     *
     * @param array<string, string> $raw     YAML form, key → column
     * @param list<SchemaColumn>    $columns unused at runtime; param kept for clarity
     *
     * @return array<string, list<string>> internal form, column → ordered keys
     */
    private static function normalisePromotions(array $raw, array $columns): array
    {
        $out = [];
        foreach ($raw as $semconvKey => $column) {
            if (!\is_string($column)) {
                throw new \InvalidArgumentException(\sprintf(
                    'promotions entry "%s" must map to a column-name string.',
                    $semconvKey,
                ));
            }
            $out[$column] ??= [];
            $out[$column][] = $semconvKey;
        }

        return $out;
    }
}
