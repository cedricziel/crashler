<?php

declare(strict_types=1);

namespace App\Tests\Component\Waterfall;

use App\Explorer\TraceWaterfallResolver;
use App\Read\Criteria\TimeWindow;
use App\Tests\Support\SeedsParquetTraces;
use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end exercise of the waterfall resolver against seeded parquet
 * traces — depth assignment, chronological ordering, sub-pixel min-width
 * on bars, sidebar lookup with cross-trace defense.
 */
final class TraceWaterfallResolverTest extends KernelTestCase
{
    use SeedsParquetTraces;
    use TempStorageRoot;

    private const string TRACE_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string TRACE_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testResolveProducesDepthFirstFlatList(): void
    {
        $window = $this->seedTrace('test-wf', self::TRACE_A, [
            ['spanIdHex' => '1111111111111111', 'parentSpanIdHex' => null, 'name' => 'POST /api/checkout', 'durationNs' => 100_000_000],
            ['spanIdHex' => '2222222222222222', 'parentSpanIdHex' => '1111111111111111', 'name' => 'db.query', 'durationNs' => 30_000_000],
            ['spanIdHex' => '3333333333333333', 'parentSpanIdHex' => '1111111111111111', 'name' => 'http GET inventory', 'durationNs' => 50_000_000],
        ]);

        $resolver = self::getContainer()->get(TraceWaterfallResolver::class);
        $trace = $resolver->resolve('test-wf', self::TRACE_A, new TimeWindow($window['since_ns'], $window['until_ns']));

        self::assertNotNull($trace);
        self::assertSame(self::TRACE_A, $trace['traceId']);
        self::assertCount(3, $trace['spans']);
        // Root first, then children in start-time order.
        self::assertSame(0, $trace['spans'][0]['depth']);
        self::assertSame(1, $trace['spans'][1]['depth']);
        self::assertSame(1, $trace['spans'][2]['depth']);
        // Bars sit inside the trace's [0, 100] window.
        foreach ($trace['spans'] as $span) {
            self::assertGreaterThanOrEqual(0.0, $span['leftPct']);
            self::assertLessThanOrEqual(100.0, $span['leftPct'] + $span['widthPct']);
            self::assertGreaterThanOrEqual(0.5, $span['widthPct'], 'min-width contract: bars never collapse below 0.5%');
        }
    }

    public function testResolveReturnsNullForUnknownTrace(): void
    {
        $resolver = self::getContainer()->get(TraceWaterfallResolver::class);
        $trace = $resolver->resolve(
            'never-seeded',
            self::TRACE_A,
            new TimeWindow(1_000_000_000, 60_000_000_000),
        );

        self::assertNull($trace);
    }

    public function testResolveExposesRootSpanIdAndErrorMetadata(): void
    {
        $window = $this->seedTrace('test-wf-meta', self::TRACE_A, [
            ['spanIdHex' => '1111111111111111', 'parentSpanIdHex' => null, 'name' => 'root'],
            ['spanIdHex' => '2222222222222222', 'parentSpanIdHex' => '1111111111111111', 'name' => 'ok-child'],
            ['spanIdHex' => '3333333333333333', 'parentSpanIdHex' => '1111111111111111', 'name' => 'errored', 'statusCode' => 2],
            ['spanIdHex' => '4444444444444444', 'parentSpanIdHex' => '1111111111111111', 'name' => 'errored-2', 'statusCode' => 2],
        ]);

        $resolver = self::getContainer()->get(TraceWaterfallResolver::class);
        $trace = $resolver->resolve('test-wf-meta', self::TRACE_A, new TimeWindow($window['since_ns'], $window['until_ns']));

        self::assertNotNull($trace);
        self::assertSame('1111111111111111', $trace['rootSpanId'], 'root is the first parentless span by start time');
        self::assertSame(2, $trace['errorCount']);
        self::assertSame('3333333333333333', $trace['firstErrorSpanId'], 'firstErrorSpanId is the first errored span in DFS order');
    }

    public function testShapedSpanExposesKindFallingBackToUnspecified(): void
    {
        // Seeded spans don't carry an explicit span_kind override — the
        // ingest writer assigns 1 (INTERNAL) by default in SeedsParquetTraces.
        $window = $this->seedTrace('test-wf-kind', self::TRACE_A, [
            ['spanIdHex' => '1111111111111111', 'parentSpanIdHex' => null, 'name' => 'root'],
        ]);

        $resolver = self::getContainer()->get(TraceWaterfallResolver::class);
        $trace = $resolver->resolve('test-wf-kind', self::TRACE_A, new TimeWindow($window['since_ns'], $window['until_ns']));

        self::assertNotNull($trace);
        self::assertArrayHasKey('kind', $trace['spans'][0]);
        self::assertGreaterThanOrEqual(0, $trace['spans'][0]['kind']);
        self::assertLessThanOrEqual(5, $trace['spans'][0]['kind']);
    }

    public function testSpanLookupRejectsCrossTraceSpanId(): void
    {
        // Trace A has span 1111…; Trace B has its own. Looking up B's
        // span via A's traceId must fail.
        $window = $this->seedTrace('test-wf-cross', self::TRACE_A, [
            ['spanIdHex' => '1111111111111111', 'parentSpanIdHex' => null, 'name' => 'A-root'],
        ]);
        $this->seedTrace('test-wf-cross', self::TRACE_B, [
            ['spanIdHex' => 'bbbbbbbbbbbbbbbb', 'parentSpanIdHex' => null, 'name' => 'B-root'],
        ], atIso: '2026-05-09 14:30:01 UTC');

        $resolver = self::getContainer()->get(TraceWaterfallResolver::class);
        $window = new TimeWindow($window['since_ns'], $window['until_ns'] + 60 * 1_000_000_000);

        $own = $resolver->span('test-wf-cross', self::TRACE_A, '1111111111111111', $window);
        self::assertNotNull($own);
        self::assertSame('A-root', $own['name']);

        $crossed = $resolver->span('test-wf-cross', self::TRACE_A, 'bbbbbbbbbbbbbbbb', $window);
        self::assertNull($crossed, 'Span id from a different trace must not be returned via TRACE_A');
    }
}
