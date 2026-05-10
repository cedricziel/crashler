<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Compute\Aggregations\Accumulator;
use App\Read\Compute\Aggregations\AccumulatorFactory;
use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Criteria\TimeWindow;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Resolves the five KPI tiles for an explorer page in one pass.
 *
 * Single-pass strategy: ONE ParquetScanner.scan() per window iterates all
 * rows in the partition globs once; multiple Accumulators (one per unique
 * KpiSpec.groupKey()) consume the same row stream in parallel. So the
 * trace explorer with 5 KPIs across 5 different (function, column) tuples
 * costs exactly 2 parquet scans (current + prior window) — not 10.
 *
 * Two windows are scanned: the current, and the immediately-prior of
 * equal width (for the ▲▼ delta).
 *
 * Failures within a single accumulator (e.g. malformed value column)
 * SHALL NOT poison the strip; the affected KPI degrades to its empty
 * value so the rest of the page renders.
 */
final readonly class KpiBundleResolver
{
    public function __construct(
        private ParquetScanner $scanner,
        private PartitionPruner $pruner,
        private PriorWindowCalculator $priorCalc,
        private WindowBucket $bucket,
        private CacheInterface $cache,
        private int $cacheTtlSeconds = 60,
    ) {
    }

    /**
     * @param list<KpiSpec> $specs
     *
     * @return list<KpiValue> in the same order as `$specs`
     */
    public function resolve(string $tenantSlug, string $signal, array $specs, TimeWindow $current): array
    {
        // Snap to the bucket grid so adjacent page loads (with `now()`
        // moving per-request) share a stable cache key.
        $current = $this->bucket->snap($current);
        $prior = $this->priorCalc->priorOf($current);

        $currentValues = $this->scanWindow($tenantSlug, $signal, $specs, $current);
        $priorValues = $this->scanWindow($tenantSlug, $signal, $specs, $prior);

        $out = [];
        foreach ($specs as $spec) {
            $now = $currentValues[$spec->groupKey()] ?? null;
            $then = $priorValues[$spec->groupKey()] ?? null;
            $delta = self::deltaPercent($now, $then);
            $out[] = new KpiValue($spec, $now, $delta);
        }

        return $out;
    }

    /**
     * Run one aggregation per unique groupKey, return values keyed by groupKey.
     *
     * @param list<KpiSpec> $specs
     *
     * @return array<string, int|float|null>
     */
    private function scanWindow(string $tenantSlug, string $signal, array $specs, TimeWindow $window): array
    {
        $globs = $this->pruner->globsFor($tenantSlug, $signal, $window);

        $cacheKey = \sprintf(
            'explorer.kpi.%s.%s.%d.%d',
            $tenantSlug,
            $signal,
            $window->sinceUnixNano,
            $window->untilUnixNano,
        );
        // Window is already bucket-snapped in resolve(); the cache key
        // therefore only changes once per bucket interval. New files
        // arriving mid-bucket are picked up after the cache TTL expires
        // (≤60s staleness), not on every individual file landing.

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($globs, $signal, $specs, $window): array {
            $item->expiresAfter($this->cacheTtlSeconds);

            $timeColumn = 'traces' === $signal ? 'start_time_unix_nano' : 'time_unix_nano';
            $predicates = [new ColumnInRange($timeColumn, $window->sinceUnixNano, $window->untilUnixNano)];

            // Build one accumulator per unique groupKey. Multiple KpiSpecs
            // sharing a (function, column) tuple share the accumulator.
            /** @var array<string, Accumulator> $accumulators */
            $accumulators = [];
            /** @var array<string, KpiSpec> $byKey */
            $byKey = [];
            foreach ($specs as $spec) {
                $key = $spec->groupKey();
                if (isset($accumulators[$key])) {
                    continue;
                }
                try {
                    $accumulators[$key] = AccumulatorFactory::for($spec->function);
                    $byKey[$key] = $spec;
                } catch (\Throwable) {
                    // Unknown function — skip this KPI; the strip renders
                    // it as empty.
                }
            }

            try {
                $scan = $this->scanner->scan($globs, $predicates, limit: \PHP_INT_MAX);
            } catch (\Throwable) {
                return array_fill_keys(array_keys($accumulators), null);
            }

            // Single pass — feed every accumulator from each row.
            $rowCount = 0;
            foreach ($scan->rows as $row) {
                ++$rowCount;
                foreach ($byKey as $key => $spec) {
                    $cellValue = null;
                    if (null !== $spec->column) {
                        $raw = $row[$spec->column] ?? null;
                        if (\is_int($raw) || \is_float($raw)) {
                            $cellValue = $raw;
                        } elseif (\is_string($raw) && is_numeric($raw)) {
                            $cellValue = str_contains($raw, '.') ? (float) $raw : (int) $raw;
                        }
                    }
                    $accumulators[$key]->feed($cellValue);
                }
            }

            // Empty window → all KPIs degrade to "no data" rather than
            // confidently reporting "0 of everything"; matches the UX
            // contract documented in the explorer-ui spec.
            if (0 === $rowCount) {
                return array_fill_keys(array_keys($accumulators), null);
            }

            $values = [];
            foreach ($accumulators as $key => $acc) {
                $values[$key] = $acc->value();
            }

            return $values;
        });
    }

    private static function deltaPercent(int|float|null $now, int|float|null $then): ?float
    {
        if (null === $now || null === $then) {
            return null;
        }
        if (0.0 === (float) $then) {
            // Avoid division-by-zero. With a zero baseline, "infinity %"
            // isn't useful information; render as "no comparable prior".
            return null;
        }

        return (($now - $then) / $then) * 100.0;
    }
}
