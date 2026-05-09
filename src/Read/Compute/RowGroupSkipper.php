<?php

declare(strict_types=1);

namespace App\Read\Compute;

use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Compute\Predicates\ColumnLowerEqual;
use App\Read\Compute\Predicates\Predicate;
use Flow\Parquet\ParquetFile\RowGroup;
use Flow\Parquet\ParquetFile\Schema;

/**
 * Tier-1 row-group push-down: decides whether a row group can be skipped
 * before its data pages are opened.
 *
 * For each numeric predicate (range, gte, lte, numeric eq) the skipper
 * compares the predicate's accepted bounds against the row group's per-column
 * min/max statistics. A group is skipped only when at least one predicate's
 * accepted set is provably disjoint from the group's [min, max] interval —
 * conservative on absent stats / missing column / non-numeric predicates.
 *
 * String predicates, JSON-attribute walks, and any predicate referencing a
 * column that's not present in the row group's schema fall through to
 * row-by-row evaluation (the `canSkip` returns false / "indeterminate").
 */
final readonly class RowGroupSkipper
{
    /**
     * @param list<Predicate> $predicates
     */
    public function canSkip(RowGroup $group, Schema $schema, array $predicates): bool
    {
        foreach ($predicates as $predicate) {
            if ($this->predicateRefutesGroup($predicate, $group, $schema)) {
                return true;
            }
        }

        return false;
    }

    private function predicateRefutesGroup(Predicate $predicate, RowGroup $group, Schema $schema): bool
    {
        $columnName = match (true) {
            $predicate instanceof ColumnInRange => $predicate->column,
            $predicate instanceof ColumnGreaterEqual => $predicate->column,
            $predicate instanceof ColumnLowerEqual => $predicate->column,
            $predicate instanceof ColumnEquals => $predicate->column,
            default => null,
        };
        if (null === $columnName) {
            return false;
        }

        if (!$schema->has($columnName)) {
            return false;
        }

        $flatColumn = self::flatColumn($schema, $columnName);
        if (null === $flatColumn) {
            return false;
        }

        $chunk = self::columnChunkOrNull($group, $flatColumn);
        if (null === $chunk) {
            return false;
        }

        $stats = $chunk->statistics();
        if (null === $stats) {
            return false;
        }

        $min = $stats->min($flatColumn) ?? $stats->minValue($flatColumn);
        $max = $stats->max($flatColumn) ?? $stats->maxValue($flatColumn);
        if (null === $min || null === $max) {
            return false;
        }
        if (!\is_int($min) && !\is_float($min)) {
            return false;
        }
        if (!\is_int($max) && !\is_float($max)) {
            return false;
        }

        return match (true) {
            $predicate instanceof ColumnInRange => $max < $predicate->low || $min > $predicate->high,
            $predicate instanceof ColumnGreaterEqual => $max < $predicate->value,
            $predicate instanceof ColumnLowerEqual => $min > $predicate->value,
            $predicate instanceof ColumnEquals => self::numericRefutesEquals($predicate, $min, $max),
            default => false,
        };
    }

    private static function numericRefutesEquals(ColumnEquals $predicate, int|float $min, int|float $max): bool
    {
        // ColumnEquals is typed string|int|bool; only int values participate
        // in numeric refutation (string comparisons are out of scope for v1).
        if (!\is_int($predicate->value)) {
            return false;
        }

        return $predicate->value < $min || $predicate->value > $max;
    }

    private static function flatColumn(Schema $schema, string $columnName): ?\Flow\Parquet\ParquetFile\Schema\FlatColumn
    {
        if (!$schema->has($columnName)) {
            return null;
        }
        $column = $schema->get($columnName);
        if ($column instanceof \Flow\Parquet\ParquetFile\Schema\FlatColumn) {
            return $column;
        }

        return null;
    }

    private static function columnChunkOrNull(RowGroup $group, \Flow\Parquet\ParquetFile\Schema\FlatColumn $column): ?\Flow\Parquet\ParquetFile\RowGroup\ColumnChunk
    {
        try {
            return $group->getColumnChunk($column);
        } catch (\Flow\Parquet\Exception\InvalidArgumentException) {
            return null;
        }
    }
}
