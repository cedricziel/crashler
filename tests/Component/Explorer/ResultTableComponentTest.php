<?php

declare(strict_types=1);

namespace App\Tests\Component\Explorer;

use App\Tests\Support\SeedsParquetLogs;
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
}
