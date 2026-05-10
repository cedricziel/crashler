<?php

declare(strict_types=1);

namespace App\Explorer;

/**
 * Declares a single filter dimension the user can chip into the QueryForm.
 *
 * The actual filter value rides through the URL query string and is parsed
 * by the per-signal SignalProfile when building search criteria — this class
 * is purely the form-layer description (label, suggestions, kind).
 *
 * `parquetColumn` is the underlying snake_case column the Parquet scanner
 * groups on for autocomplete. When `null`, the field is high-cardinality or
 * structurally not a column (e.g. trace_id) and the UI MUST NOT eagerly
 * autocomplete it.
 */
final readonly class FilterDefinition
{
    public const string KIND_TEXT = 'text';
    public const string KIND_ENUM = 'enum';

    /**
     * @param list<string> $suggestions
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $kind = self::KIND_TEXT,
        public array $suggestions = [],
        public ?string $parquetColumn = null,
    ) {
    }

    /**
     * The UI eagerly fetches autocomplete options when both:
     * - the dimension is text-kind (enums already have a fixed option set), and
     * - the parquet column is known (i.e. groupable, low-cardinality).
     */
    public function shouldAutocomplete(): bool
    {
        return self::KIND_TEXT === $this->kind && null !== $this->parquetColumn;
    }
}
