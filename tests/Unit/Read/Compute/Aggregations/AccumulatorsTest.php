<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Compute\Aggregations;

use App\Read\Compute\Aggregations\AccumulatorFactory;
use App\Read\Compute\Aggregations\AvgAccumulator;
use App\Read\Compute\Aggregations\CountAccumulator;
use App\Read\Compute\Aggregations\MaxAccumulator;
use App\Read\Compute\Aggregations\MinAccumulator;
use App\Read\Compute\Aggregations\SumAccumulator;
use PHPUnit\Framework\TestCase;

/**
 * The accumulators are the per-group reducers used by AggregatingScanner.
 * They are simple, but the contract (null-skip, sample-count, fresh-state)
 * is load-bearing for the aggregate API; locks it in.
 */
final class AccumulatorsTest extends TestCase
{
    public function testCountAccumulatesEveryRow(): void
    {
        $a = new CountAccumulator();
        $a->feed(1);
        $a->feed(0);
        $a->feed(null);
        $a->feed(5);

        // CountAccumulator counts the rows fed, regardless of value —
        // null-skip is a property of typed columns (sum/avg/min/max),
        // not of the COUNT(*) semantics.
        self::assertSame(4, $a->value());
        self::assertSame(4, $a->sampleCount());
    }

    public function testSumAddsValuesAndIgnoresNulls(): void
    {
        $a = new SumAccumulator();
        self::assertNull($a->value());
        $a->feed(2);
        $a->feed(null);
        $a->feed(3);
        $a->feed(0);
        self::assertSame(5, $a->value());
        self::assertSame(3, $a->sampleCount());
    }

    public function testSumWithFloatsPromotesToFloat(): void
    {
        $a = new SumAccumulator();
        $a->feed(1);
        $a->feed(0.5);
        self::assertSame(1.5, $a->value());
    }

    public function testAvgReturnsNullWithoutSamples(): void
    {
        $a = new AvgAccumulator();
        self::assertNull($a->value());
        $a->feed(null);
        self::assertNull($a->value());
        self::assertSame(0, $a->sampleCount());
    }

    public function testAvgComputesMean(): void
    {
        $a = new AvgAccumulator();
        $a->feed(2);
        $a->feed(4);
        $a->feed(null); // skipped
        $a->feed(6);
        self::assertSame(4.0, $a->value());
        self::assertSame(3, $a->sampleCount());
    }

    public function testMinTracksLowestValue(): void
    {
        $a = new MinAccumulator();
        self::assertNull($a->value());
        $a->feed(5);
        $a->feed(2);
        $a->feed(null);
        $a->feed(8);
        self::assertSame(2, $a->value());
        self::assertSame(3, $a->sampleCount());
    }

    public function testMaxTracksHighestValue(): void
    {
        $a = new MaxAccumulator();
        self::assertNull($a->value());
        $a->feed(5);
        $a->feed(2);
        $a->feed(null);
        $a->feed(8);
        self::assertSame(8, $a->value());
        self::assertSame(3, $a->sampleCount());
    }

    public function testFactoryReturnsFreshInstancePerCall(): void
    {
        $a = AccumulatorFactory::for('sum');
        $b = AccumulatorFactory::for('sum');
        self::assertNotSame($a, $b);
    }

    public function testFactoryDispatchesAllSupportedFunctions(): void
    {
        self::assertInstanceOf(CountAccumulator::class, AccumulatorFactory::for('count'));
        self::assertInstanceOf(SumAccumulator::class, AccumulatorFactory::for('sum'));
        self::assertInstanceOf(AvgAccumulator::class, AccumulatorFactory::for('avg'));
        self::assertInstanceOf(MinAccumulator::class, AccumulatorFactory::for('min'));
        self::assertInstanceOf(MaxAccumulator::class, AccumulatorFactory::for('max'));
    }

    public function testFactoryRejectsUnknownFunction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported aggregation function `p99`/');
        AccumulatorFactory::for('p99');
    }

    public function testFunctionRequiresColumn(): void
    {
        self::assertFalse(AccumulatorFactory::functionRequiresColumn('count'));
        self::assertTrue(AccumulatorFactory::functionRequiresColumn('sum'));
        self::assertTrue(AccumulatorFactory::functionRequiresColumn('avg'));
        self::assertTrue(AccumulatorFactory::functionRequiresColumn('min'));
        self::assertTrue(AccumulatorFactory::functionRequiresColumn('max'));
    }
}
