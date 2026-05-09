<?php

declare(strict_types=1);

namespace App\Read\Compute;

use App\Read\Compute\Aggregations\Accumulator;
use App\Read\Compute\Aggregations\AccumulatorFactory;
use App\Read\Compute\Predicates\Predicate;

/**
 * Streaming aggregator. Iterates the same partition glob set as
 * {@see ParquetScanner}, applies the same predicate AND-composition, but
 * feeds matching rows into per-group accumulators instead of materialising
 * them. The scanner is intentionally single-purpose: row-yielding lives in
 * {@see ParquetScanner}, not here.
 *
 * v1 limitations (tracked under add-read-aggregations):
 *   - Single-column groupBy
 *   - No interval bucketing
 *   - Functions: count, sum, avg, min, max (percentiles deferred)
 *   - No row-group push-down (scans every row group; the predicate path
 *     handles per-row filtering)
 */
final readonly class AggregatingScanner
{
    /**
     * @param int $maxGroups cardinality cap; exceeded → exception → 400
     */
    public function __construct(
        private ParquetScanner $rowScanner,
        private int $maxGroups = 200,
    ) {
    }

    /**
     * @param list<string>    $partitionGlobs
     * @param list<Predicate> $predicates
     * @param ?string         $groupByColumn snake_case column name; null = no grouping (single result row)
     * @param ?string         $valueColumn   snake_case column whose values feed the accumulator (null only for `count`)
     */
    public function aggregate(
        array $partitionGlobs,
        array $predicates,
        string $function,
        ?string $valueColumn,
        ?string $groupByColumn,
    ): AggregateResult {
        // Reuse ParquetScanner with no row limit — we want every matching
        // row to feed accumulators. The execution-timeout still applies.
        $scan = $this->rowScanner->scan($partitionGlobs, $predicates, limit: \PHP_INT_MAX);

        /** @var array<string, Accumulator> $accumulators */
        $accumulators = [];
        /** @var array<string, mixed> $groupKeyValues */
        $groupKeyValues = [];

        foreach ($scan->rows as $row) {
            $groupKey = null === $groupByColumn ? '' : (string) ($row[$groupByColumn] ?? '');
            if (!isset($accumulators[$groupKey])) {
                if (\count($accumulators) >= $this->maxGroups) {
                    throw new AggregationCardinalityExceededException(\sprintf(
                        'Aggregation produced more than %d distinct groups. Tighten the filter or reduce the group-by.',
                        $this->maxGroups,
                    ));
                }
                $accumulators[$groupKey] = AccumulatorFactory::for($function);
                $groupKeyValues[$groupKey] = null === $groupByColumn ? null : ($row[$groupByColumn] ?? null);
            }

            $cellValue = null;
            if (null !== $valueColumn) {
                $raw = $row[$valueColumn] ?? null;
                if (\is_int($raw) || \is_float($raw)) {
                    $cellValue = $raw;
                } elseif (\is_string($raw) && is_numeric($raw)) {
                    $cellValue = str_contains($raw, '.') ? (float) $raw : (int) $raw;
                }
            }

            $accumulators[$groupKey]->feed($cellValue);
        }

        // Materialise into result rows sorted by group key (stable).
        $rows = [];
        ksort($accumulators);
        foreach ($accumulators as $key => $acc) {
            $entry = [
                'group' => null === $groupByColumn
                    ? new \stdClass()
                    : [$groupByColumn => $groupKeyValues[$key]],
                'function' => $function,
                'value' => $acc->value(),
                'sample_count' => $acc->sampleCount(),
            ];
            if (null !== $valueColumn) {
                $entry['column'] = $valueColumn;
            }
            $rows[] = $entry;
        }

        return new AggregateResult($rows);
    }
}
