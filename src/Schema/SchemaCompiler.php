<?php

declare(strict_types=1);

namespace App\Schema;

use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\ParquetFile\Schema\FlatColumn;
use Flow\Parquet\ParquetFile\Schema\Repetition;

/**
 * Translates a SchemaDefinition (loaded from YAML) into a flow-php Schema
 * suitable for the Parquet writer. Always appends the universal
 * `_schema_version` (int32 REQUIRED) and `_schema_id` (string REQUIRED)
 * columns AFTER the YAML-declared columns so every Parquet file Crashler
 * produces — across logs, traces, metrics, and any future signal — carries
 * its schema identity in-band.
 */
final class SchemaCompiler
{
    public const string COLUMN_SCHEMA_VERSION = '_schema_version';
    public const string COLUMN_SCHEMA_ID = '_schema_id';

    public static function toFlowSchema(SchemaDefinition $definition): Schema
    {
        $columns = [];
        foreach ($definition->columns as $col) {
            $columns[] = self::flatColumn($col->name, $col->type, $col->repetition);
        }

        // Universal infrastructure columns. Reserved name prefix '_schema_'
        // is enforced by SchemaDefinition's validator so YAMLs cannot
        // clash with these.
        $columns[] = FlatColumn::int32(self::COLUMN_SCHEMA_VERSION, Repetition::REQUIRED);
        $columns[] = FlatColumn::string(self::COLUMN_SCHEMA_ID, Repetition::REQUIRED);

        return Schema::with(...$columns);
    }

    private static function flatColumn(string $name, string $type, string $repetition): FlatColumn
    {
        $rep = 'required' === $repetition ? Repetition::REQUIRED : Repetition::OPTIONAL;

        return match ($type) {
            'int32' => FlatColumn::int32($name, $rep),
            'int64' => FlatColumn::int64($name, $rep),
            'string' => FlatColumn::string($name, $rep),
            'boolean' => FlatColumn::boolean($name, $rep),
            'float' => FlatColumn::float($name, $rep),
            'dateTime' => FlatColumn::dateTime($name, $rep),
            default => throw new \LogicException(\sprintf(
                'Unhandled SchemaDefinition type "%s" for column "%s"; SchemaDefinition validator should have rejected this earlier.',
                $type,
                $name,
            )),
        };
    }
}
