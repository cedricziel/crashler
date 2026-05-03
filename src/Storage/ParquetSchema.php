<?php

declare(strict_types=1);

namespace App\Storage;

use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\ParquetFile\Schema\FlatColumn;
use Flow\Parquet\ParquetFile\Schema\Repetition;

/**
 * Defines the Parquet column layout for a single ingested OTLP request file.
 *
 * The shape mirrors specs/log-storage/spec.md:
 * Requirement: Parquet schema and types. All columns are primitive in v1
 * (no native maps); resource_attributes_json and attributes_json carry the
 * AnyValue/KeyValue payload as JSON strings to preserve fidelity.
 */
final class ParquetSchema
{
    public static function definition(): Schema
    {
        return Schema::with(
            FlatColumn::int64('time_unix_nano', Repetition::REQUIRED),
            FlatColumn::int64('observed_time_unix_nano', Repetition::OPTIONAL),
            FlatColumn::int32('severity_number', Repetition::OPTIONAL),
            FlatColumn::string('severity_text', Repetition::OPTIONAL),
            FlatColumn::string('body_json', Repetition::OPTIONAL),
            FlatColumn::string('service_name', Repetition::OPTIONAL),
            FlatColumn::string('scope_name', Repetition::OPTIONAL),
            FlatColumn::string('scope_version', Repetition::OPTIONAL),
            FlatColumn::string('trace_id_hex', Repetition::OPTIONAL),
            FlatColumn::string('span_id_hex', Repetition::OPTIONAL),
            FlatColumn::int32('flags', Repetition::OPTIONAL),
            FlatColumn::string('resource_attributes_json', Repetition::REQUIRED),
            FlatColumn::string('attributes_json', Repetition::REQUIRED),
        );
    }
}
