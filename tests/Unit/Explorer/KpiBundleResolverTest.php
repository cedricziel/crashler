<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explorer;

use App\Explorer\KpiBundleResolver;
use App\Explorer\KpiSpec;
use App\Explorer\PriorWindowCalculator;
use App\Explorer\WindowBucket;
use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Criteria\TimeWindow;
use App\Tests\Support\SeedsParquetLogs;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Pins the two contracts that `KpiStripComponentTest` exercises end-to-end
 * but cannot prove in isolation:
 *
 *   1. **De-dup**: two KpiSpecs sharing a `groupKey()` resolve through one
 *      shared accumulator — the second spec is NOT a fresh scan, and the
 *      output preserves spec order with both KpiValues carrying the same
 *      accumulated value.
 *   2. **Null prior**: when the prior window contains zero matching rows,
 *      `KpiValue::deltaPercent` is `null`. The strip degrades to "no comparable
 *      prior" rather than reporting `+∞%` against a zero baseline.
 */
final class KpiBundleResolverTest extends TestCase
{
    use SeedsParquetLogs;
    use TempStorageRoot;

    private function makeResolver(): KpiBundleResolver
    {
        return new KpiBundleResolver(
            scanner: new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 30),
            pruner: new PartitionPruner($this->tempStorageRoot()),
            priorCalc: new PriorWindowCalculator(),
            bucket: new WindowBucket(bucketSeconds: 60),
            cache: new ArrayAdapter(),
            cacheTtlSeconds: 60,
        );
    }

    public function testTwoSpecsSharingAGroupKeyResolveToTheSameDedupedValue(): void
    {
        $window = $this->seedLogs('test-kpi-dedup', ['a', 'b', 'c']);

        // Two distinct KpiSpecs (different id/label) that resolve to the same
        // (function, column) tuple — and therefore the same groupKey().
        $specA = new KpiSpec(id: 'total', label: 'Total', function: 'count');
        $specB = new KpiSpec(id: 'rate', label: 'Rate /min', function: 'count', column: null, unit: '/min');
        self::assertSame($specA->groupKey(), $specB->groupKey(), 'precondition: both specs share a groupKey');

        $values = $this->makeResolver()->resolve(
            'test-kpi-dedup',
            'logs',
            [$specA, $specB],
            new TimeWindow($window['since_ns'], $window['until_ns']),
        );

        self::assertCount(2, $values, 'output cardinality matches input cardinality, not the deduped scan count');
        self::assertSame('total', $values[0]->spec->id);
        self::assertSame('rate', $values[1]->spec->id, 'output preserves spec order');
        self::assertSame(3, $values[0]->value);
        self::assertSame(3, $values[1]->value, 'spec B reads the same accumulated count as spec A — single scan, shared accumulator');
    }

    public function testDistinctGroupKeysProduceTheirOwnAggregatedValues(): void
    {
        // Three rows; severity_number = 9 for every record per SeedsParquetLogs.
        $window = $this->seedLogs('test-kpi-distinct', ['x', 'y', 'z'], severity: 9);

        $count = new KpiSpec(id: 'total', label: 'Total', function: 'count');
        $avgSeverity = new KpiSpec(id: 'avg_severity', label: 'Avg severity', function: 'avg', column: 'severity_number');
        self::assertNotSame($count->groupKey(), $avgSeverity->groupKey(), 'precondition: different groupKeys');

        $values = $this->makeResolver()->resolve(
            'test-kpi-distinct',
            'logs',
            [$count, $avgSeverity],
            new TimeWindow($window['since_ns'], $window['until_ns']),
        );

        self::assertSame(3, $values[0]->value);
        self::assertSame(9.0, $values[1]->value);
    }

    public function testPriorWindowWithoutDataYieldsNullDelta(): void
    {
        // SeedsParquetLogs writes rows at `clockNano + i*1ms` for a clock at
        // 14:30:00 UTC, and returns the [clockNano-60s, clockNano+60s] window.
        // PriorWindowCalculator therefore picks [clockNano-180s, clockNano-60s],
        // which contains zero rows.
        $window = $this->seedLogs('test-kpi-null-prior', ['a', 'b', 'c']);

        $values = $this->makeResolver()->resolve(
            'test-kpi-null-prior',
            'logs',
            [new KpiSpec(id: 'total', label: 'Total', function: 'count')],
            new TimeWindow($window['since_ns'], $window['until_ns']),
        );

        self::assertSame(3, $values[0]->value, 'current window catches the seeded rows');
        self::assertNull(
            $values[0]->deltaPercent,
            'prior window has no data — delta must be null, not "+∞%" against a zero baseline',
        );
    }

    public function testCurrentWindowWithoutDataYieldsNullValueAndNullDelta(): void
    {
        // No seeded partitions. Both current and prior scan to zero rows; the
        // resolver degrades the KPI to (null, null) rather than reporting "0".
        $values = $this->makeResolver()->resolve(
            'never-seeded-tenant',
            'logs',
            [new KpiSpec(id: 'total', label: 'Total', function: 'count')],
            new TimeWindow(sinceUnixNano: 1_780_000_000_000_000_000, untilUnixNano: 1_780_000_060_000_000_000),
        );

        self::assertNull($values[0]->value, 'empty current window must degrade to null, not "0"');
        self::assertNull($values[0]->deltaPercent);
    }
}
