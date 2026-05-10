<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Compute\AggregatingScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Criteria\TimeWindow;

/**
 * Resolves the five KPI tiles for an explorer page in one pass.
 *
 * Each unique (function, column) tuple SHALL run at most ONE aggregation
 * scan per window — KPIs that share a tuple share the same scan. Two
 * windows are scanned: the current, and the immediately-prior of equal
 * width (for the ▲▼ delta).
 *
 * Failures in a single sub-scan SHALL NOT poison the strip; the affected
 * KPI degrades to its empty value (`—` in the rendered tile) so the rest
 * of the page renders.
 */
final readonly class KpiBundleResolver
{
    public function __construct(
        private AggregatingScanner $scanner,
        private PartitionPruner $pruner,
        private PriorWindowCalculator $priorCalc,
    ) {
    }

    /**
     * @param list<KpiSpec> $specs
     *
     * @return list<KpiValue> in the same order as `$specs`
     */
    public function resolve(string $tenantSlug, string $signal, array $specs, TimeWindow $current): array
    {
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
        $timeColumn = 'traces' === $signal ? 'start_time_unix_nano' : 'time_unix_nano';
        $predicates = [new ColumnInRange($timeColumn, $window->sinceUnixNano, $window->untilUnixNano)];

        /** @var array<string, KpiSpec> $byKey */
        $byKey = [];
        foreach ($specs as $spec) {
            $byKey[$spec->groupKey()] = $spec;
        }

        $values = [];
        foreach ($byKey as $key => $spec) {
            try {
                $result = $this->scanner->aggregate($globs, $predicates, $spec->function, $spec->column, null);
                $values[$key] = self::firstRowValue($result->rows);
            } catch (\Throwable) {
                // Per-KPI failures degrade gracefully; resolver MUST NOT
                // poison the rest of the strip.
                $values[$key] = null;
            }
        }

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function firstRowValue(array $rows): int|float|null
    {
        if ([] === $rows) {
            return null;
        }
        $value = $rows[0]['value'] ?? null;

        return \is_int($value) || \is_float($value) ? $value : null;
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
