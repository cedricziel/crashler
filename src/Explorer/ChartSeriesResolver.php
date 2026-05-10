<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Criteria\TimeWindow;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Builds the chart payload for the explorer's time-series canvas.
 *
 * Output shape (consumed directly by chart_controller.js + uPlot):
 *   {
 *     "x":      [t0_seconds, t1_seconds, ...],  // bucket-start unix seconds
 *     "series": [
 *       {"label": "checkout", "values": [count_at_t0, count_at_t1, ...]},
 *       {"label": "payments", "values": [...]},
 *     ]
 *   }
 *
 * Strategy: ONE ParquetScanner.scan() per window iterates every row in
 * the partition globs; the resolver buckets in PHP by (groupByValue,
 * intervalBucket) and returns the matrix. Series are capped at
 * MAX_SERIES — beyond that, the long tail collapses into an "other"
 * series (or the whole long tail is dropped, configurable).
 *
 * Cache key follows the same WindowBucket-snapped pattern as the
 * other resolvers — a 60s TTL keeps adjacent chart loads cheap on
 * actively-ingesting tenants.
 */
final readonly class ChartSeriesResolver
{
    /** Hard cap on distinct series to avoid runaway DOM. */
    public const int MAX_SERIES = 8;

    public function __construct(
        private ParquetScanner $scanner,
        private PartitionPruner $pruner,
        private WindowBucket $bucket,
        private CacheInterface $cache,
        private int $cacheTtlSeconds = 60,
    ) {
    }

    /**
     * @return array{x: list<int>, series: list<array{label: string, values: list<int|float|null>}>}
     */
    public function series(string $tenantSlug, string $signal, TimeWindow $window, string $groupByColumn): array
    {
        $window = $this->bucket->snap($window);

        // Pick an interval that yields ~30 buckets across the window —
        // detailed enough to see spikes, coarse enough that 670-file
        // partitions don't produce noise.
        $intervalNs = $this->chooseIntervalNs($window);

        $cacheKey = \sprintf(
            'explorer.chart.%s.%s.%d.%d.%s.%d',
            $tenantSlug,
            $signal,
            $window->sinceUnixNano,
            $window->untilUnixNano,
            $groupByColumn,
            $intervalNs,
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($tenantSlug, $signal, $window, $groupByColumn, $intervalNs): array {
            $item->expiresAfter($this->cacheTtlSeconds);

            return $this->compute($tenantSlug, $signal, $window, $groupByColumn, $intervalNs);
        });
    }

    /**
     * @return array{x: list<int>, series: list<array{label: string, values: list<int|float|null>}>}
     */
    private function compute(string $tenantSlug, string $signal, TimeWindow $window, string $groupByColumn, int $intervalNs): array
    {
        $globs = $this->pruner->globsFor($tenantSlug, $signal, $window);
        $timeColumn = 'traces' === $signal ? 'start_time_unix_nano' : 'time_unix_nano';
        $predicates = [new ColumnInRange($timeColumn, $window->sinceUnixNano, $window->untilUnixNano)];

        // Bucket grid pinned to the window's start — every series uses
        // the same x positions even when some buckets are empty.
        $bucketStarts = [];
        for ($t = $window->sinceUnixNano; $t < $window->untilUnixNano; $t += $intervalNs) {
            $bucketStarts[] = $t;
        }

        try {
            $scan = $this->scanner->scan($globs, $predicates, limit: \PHP_INT_MAX);
        } catch (\Throwable) {
            return ['x' => $this->nsToSeconds($bucketStarts), 'series' => []];
        }

        // counts[seriesLabel][bucketIndex] = int
        /** @var array<string, array<int, int>> $counts */
        $counts = [];
        $seriesTotals = [];

        foreach ($scan->rows as $row) {
            $rawTime = $row[$timeColumn] ?? null;
            if (!\is_int($rawTime)) {
                continue;
            }
            $bucketIdx = (int) (($rawTime - $window->sinceUnixNano) / $intervalNs);
            if ($bucketIdx < 0 || $bucketIdx >= \count($bucketStarts)) {
                continue;
            }
            $rawLabel = $row[$groupByColumn] ?? null;
            $label = \is_string($rawLabel) && '' !== $rawLabel ? $rawLabel : '(none)';

            $counts[$label][$bucketIdx] = ($counts[$label][$bucketIdx] ?? 0) + 1;
            $seriesTotals[$label] = ($seriesTotals[$label] ?? 0) + 1;
        }

        // Cap at MAX_SERIES — keep the highest-volume groups, drop the long tail.
        arsort($seriesTotals);
        $kept = \array_slice(array_keys($seriesTotals), 0, self::MAX_SERIES);

        $series = [];
        foreach ($kept as $label) {
            $values = [];
            foreach ($bucketStarts as $idx => $_) {
                $values[] = $counts[$label][$idx] ?? 0;
            }
            $series[] = ['label' => $label, 'values' => $values];
        }

        return [
            'x' => $this->nsToSeconds($bucketStarts),
            'series' => $series,
        ];
    }

    /**
     * Pick a bucket interval that yields roughly 30 points across the window.
     */
    private function chooseIntervalNs(TimeWindow $window): int
    {
        $widthNs = $window->untilUnixNano - $window->sinceUnixNano;
        $target = (int) ($widthNs / 30);
        // Snap to a round-friendly value so the x-axis labels are clean.
        foreach ([1_000_000_000, 5_000_000_000, 10_000_000_000, 30_000_000_000, 60_000_000_000, 5 * 60_000_000_000, 15 * 60_000_000_000, 60 * 60_000_000_000] as $candidate) {
            if ($target <= $candidate) {
                return $candidate;
            }
        }

        return 60 * 60_000_000_000; // 1h max bucket
    }

    /**
     * @param list<int> $nanos
     *
     * @return list<int> unix seconds (uPlot's time scale unit)
     */
    private function nsToSeconds(array $nanos): array
    {
        return array_map(static fn (int $ns): int => intdiv($ns, 1_000_000_000), $nanos);
    }
}
