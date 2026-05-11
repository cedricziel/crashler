<?php

declare(strict_types=1);

namespace App\Tests\Component\Explorer;

use App\Tests\Support\SeedsParquetLogs;
use App\Tests\Support\SeedsParquetTraces;
use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Hydrates the ResultTable Live Component. Covers all four states:
 * loading (zero window), empty (window but no rows), populated
 * (seeded parquet), and the regression case for the prod 500 — a row
 * with `time_unix_nano` going through the template's time formatter
 * without tripping twig's |date filter on a float.
 */
final class ResultTableComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use SeedsParquetLogs;
    use SeedsParquetTraces;
    use TempStorageRoot;

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testZeroWindowRendersSkeletonRows(): void
    {
        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => '',
            'signal' => 'logs',
            'windowSinceNs' => 0,
            'windowUntilNs' => 0,
        ]);

        $rendered = (string) $component->render();

        self::assertStringContainsString('aria-busy="true"', $rendered);
        // 5 skeleton rows declared in the template.
        self::assertSame(5, substr_count($rendered, 'aria-busy="true"'));
    }

    public function testHydratedWindowWithNoDataRendersEmptyStateCopy(): void
    {
        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'no-data',
            'signal' => 'logs',
            'windowSinceNs' => 1_000_000_000,
            'windowUntilNs' => 2_000_000_000,
        ]);

        $rendered = (string) $component->render();

        self::assertStringContainsString('No rows match', $rendered);
        self::assertStringContainsString('Try widening the time range', $rendered);
    }

    public function testHydratedWindowWithSeededLogsRendersPopulatedRows(): void
    {
        $window = $this->seedLogs('test-table', ['hello world', 'second log'], service: 'checkout');

        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'test-table',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // Body content present.
        self::assertStringContainsString('hello world', $rendered);
        self::assertStringContainsString('second log', $rendered);
        // Service column is filled with the resource_service_name.
        self::assertStringContainsString('checkout', $rendered);
        // Empty-state copy is NOT shown when rows are present.
        self::assertStringNotContainsString('No rows match', $rendered);
    }

    /**
     * Pins the bug fix in commit bb3f247 — the populated table tried to
     * format a nanosecond timestamp via `((ns // 1000000) / 1000)|date(…)`
     * which produces a float, and twig's |date filter rejects floats with
     * `DateMalformedStringException`. The fix uses integer arithmetic.
     */
    public function testNanosecondTimestampRendersAsHmsMillisWithoutThrowing(): void
    {
        $window = $this->seedLogs('test-time', ['x']);

        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'test-time',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        // The render must complete (no DateMalformedStringException).
        $rendered = (string) $component->render();

        // The Time column should match HH:MM:SS.mmm somewhere in the row.
        self::assertMatchesRegularExpression('/\d{2}:\d{2}:\d{2}\.\d{3}/', $rendered);
    }

    public function testTraceIdHexCellLinksToWaterfallDetailPage(): void
    {
        $traceHex = str_repeat('a1b2', 8); // 32 lowercase hex chars
        $window = $this->seedLogs('test-trace-link', ['hello'], traceIdHex: $traceHex);

        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'test-trace-link',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // Anchor points at the waterfall route with the seeded trace id.
        self::assertMatchesRegularExpression(
            '#<a [^>]*href="/tenants/test-trace-link/traces/'.$traceHex.'"#',
            $rendered,
            'trace_id_hex cell must wrap the value in a link to the waterfall page',
        );
        // Display is truncated to 10 chars + ellipsis (column width is 10ch).
        self::assertStringContainsString(substr($traceHex, 0, 10).'…', $rendered);
    }

    public function testTracesExplorerRowsLinkToWaterfallDetailPage(): void
    {
        $traceHex = str_repeat('c0de', 8); // 32 lowercase hex chars
        $window = $this->seedTrace('test-traces-link', $traceHex, [
            ['spanIdHex' => 'abcdef0123456789', 'name' => 'GET /api/orders'],
        ]);

        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'test-traces-link',
            'signal' => 'traces',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // The new trace_id_hex column renders as a link to the waterfall.
        self::assertMatchesRegularExpression(
            '#<a [^>]*href="/tenants/test-traces-link/traces/'.$traceHex.'"#',
            $rendered,
            'traces explorer rows must expose a link to the waterfall page',
        );
        // And the row data still shows up (span name in the Span column).
        self::assertStringContainsString('GET /api/orders', $rendered);
    }

    public function testTraceIdHexFallsBackToDashWhenRowHasNoTraceId(): void
    {
        // Default seed leaves traceId null.
        $window = $this->seedLogs('test-no-trace', ['hello']);

        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'test-no-trace',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // No anchor pointing at /traces/<hex>, but the row still renders.
        self::assertDoesNotMatchRegularExpression('#href="/tenants/test-no-trace/traces/[0-9a-f]{32}"#', $rendered);
        self::assertStringContainsString('hello', $rendered);
    }

    public function testHydratedRenderShowsPaginatorOnFirstPage(): void
    {
        $window = $this->seedLogs('test-pager', ['hello']);

        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'test-pager',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // Paginator visible. First page → prev disabled.
        self::assertStringContainsString('aria-label="Result pagination"', $rendered);
        self::assertStringContainsString('page 1', $rendered);
        self::assertMatchesRegularExpression('/data-live-action-param="prevPage"[^>]*disabled/', $rendered);
    }

    public function testNextPageActionAdvancesCursor(): void
    {
        // Seed enough rows that one page (50) is exceeded. Page size is
        // 50 by default; seed 60 rows so a next cursor exists.
        $window = $this->seedLogs('test-pager-next', array_map(static fn ($i) => 'row '.$i, range(1, 60)));

        $component = $this->createLiveComponent('Explorer:ResultTable', [
            'tenantSlug' => 'test-pager-next',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        // First render — page 1, prev disabled.
        $first = (string) $component->render();
        self::assertStringContainsString('page 1', $first);

        // Trigger nextPage LiveAction.
        $component->call('nextPage');
        $second = (string) $component->render();
        self::assertStringContainsString('page 2', $second);
        // After advancing, prev is no longer disabled.
        self::assertDoesNotMatchRegularExpression('/data-live-action-param="prevPage"[^>]*disabled/', $second);

        // prevPage walks back to page 1.
        $component->call('prevPage');
        $third = (string) $component->render();
        self::assertStringContainsString('page 1', $third);
    }
}
