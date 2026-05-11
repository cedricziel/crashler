<?php

declare(strict_types=1);

namespace App\Tests\Component\Explorer;

use App\Tests\Support\SeedsParquetLogs;
use App\Tests\Support\SeedsParquetTraces;
use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Hydrates the KpiStrip Live Component as the browser's deferred-load
 * request would. Pre-hydration (zero window) renders skeleton tiles;
 * post-hydration with real parquet data renders populated tiles with
 * delta-vs-prior values.
 */
final class KpiStripComponentTest extends KernelTestCase
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

    public function testEmptyWindowRendersAllTilesAsSkeleton(): void
    {
        $component = $this->createLiveComponent('Explorer:KpiStrip', [
            'tenantSlug' => '',
            'signal' => 'logs',
            'windowSinceNs' => 0,
            'windowUntilNs' => 0,
        ]);

        $rendered = (string) $component->render();

        // Loading state markers — every tile gets aria-busy="true".
        self::assertStringContainsString('aria-busy="true"', $rendered);
        // Five skeleton tiles, one per KpiSpec in LogsProfile.
        self::assertSame(5, substr_count($rendered, 'data-testid="kpi-tile-'));
        self::assertStringNotContainsString('class="kpi-tile kpi-tile--populated', $rendered);
    }

    public function testHydratedWindowWithoutDataRendersEmptyTiles(): void
    {
        // No seeded parquet; resolver returns null values for every KPI.
        $window = ['since_ns' => 1_000_000_000, 'until_ns' => 2_000_000_000];

        $component = $this->createLiveComponent('Explorer:KpiStrip', [
            'tenantSlug' => 'never-seeded',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // Empty-state — em-dash for value, "no data" copy.
        self::assertSame(5, substr_count($rendered, 'kpi-tile--empty'));
        self::assertStringContainsString('no data', $rendered);
    }

    public function testTracesKpiStripScalesNanosecondDurationsToHumanUnits(): void
    {
        // 4.2ms span → avg/max(duration_nano) = 4_200_000 ns.
        $traceHex = str_repeat('beef', 8);
        $window = $this->seedTrace('test-kpi-dur', $traceHex, [
            ['spanIdHex' => 'feedfacecafebabe', 'name' => 'GET /', 'durationNs' => 4_200_000],
        ]);

        $component = $this->createLiveComponent('Explorer:KpiStrip', [
            'tenantSlug' => 'test-kpi-dur',
            'signal' => 'traces',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // Raw nanos MUST NOT leak through to a duration tile.
        self::assertStringNotContainsString('4 200 000', $rendered);
        self::assertStringNotContainsString('4200000', $rendered);
        // Both duration KPIs scale to "4.20 ms".
        self::assertStringContainsString('4.20 ms', $rendered);
    }

    public function testHydratedWindowWithSeededLogsRendersPopulatedTotal(): void
    {
        $window = $this->seedLogs('test-kpi', ['a', 'b', 'c'], service: 'checkout');

        $component = $this->createLiveComponent('Explorer:KpiStrip', [
            'tenantSlug' => 'test-kpi',
            'signal' => 'logs',
            'windowSinceNs' => $window['since_ns'],
            'windowUntilNs' => $window['until_ns'],
        ]);

        $rendered = (string) $component->render();

        // The 'total' KPI is a count() — three rows seeded → "3" rendered.
        self::assertStringContainsString('data-testid="kpi-tile-total"', $rendered);
        self::assertStringContainsString('kpi-tile--populated', $rendered);
        // The integer formatter prints '3' (no thousands separator until 1000+).
        self::assertMatchesRegularExpression('/data-testid="kpi-tile-total".*?>\s*3\b/s', $rendered);
    }
}
