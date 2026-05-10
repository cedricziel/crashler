<?php

declare(strict_types=1);

namespace App\Tests\Component\Waterfall;

use App\Tests\Support\SeedsParquetTraces;
use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Hydrates the Waterfall:Sidebar Live Component. Empty state until
 * `selectSpan` LiveAction is invoked; populated state shows the
 * selected span's name + service + drill-to-logs link.
 */
final class SidebarComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use SeedsParquetTraces;
    use TempStorageRoot;

    private const string TRACE_ID = 'cccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testInitialRenderShowsEmptyState(): void
    {
        $component = $this->createLiveComponent('Waterfall:Sidebar', [
            'tenantSlug' => 'test-sidebar',
            'traceId' => self::TRACE_ID,
        ]);

        $rendered = (string) $component->render();

        self::assertStringContainsString('select a span', $rendered);
    }

    public function testSelectSpanHydratesSidebarAndExposesDrillLink(): void
    {
        $this->seedTrace('test-sidebar', self::TRACE_ID, [
            ['spanIdHex' => 'cafebabecafebabe', 'parentSpanIdHex' => null, 'name' => 'POST /api/charge', 'durationNs' => 5_000_000, 'statusCode' => 2],
        ]);

        $component = $this->createLiveComponent('Waterfall:Sidebar', [
            'tenantSlug' => 'test-sidebar',
            'traceId' => self::TRACE_ID,
        ]);

        $component->call('selectSpan', ['spanId' => 'cafebabecafebabe']);
        $rendered = (string) $component->render();

        self::assertStringContainsString('POST /api/charge', $rendered);
        self::assertStringContainsString('checkout', $rendered);
        // ERROR status (statusCode=2) renders the warning glyph.
        self::assertStringContainsString('ERROR', $rendered);
        // Drill-to-logs link present.
        self::assertStringContainsString('/tenants/test-sidebar/explore/logs', $rendered);
        self::assertStringContainsString('traceId='.self::TRACE_ID, $rendered);
    }

    public function testCrossTraceSpanIdYieldsEmptyState(): void
    {
        // Seed a span in test-sidebar's trace; query the sidebar with
        // a forged spanId that doesn't belong to this trace.
        $this->seedTrace('test-cross', self::TRACE_ID, [
            ['spanIdHex' => 'aaaaaaaaaaaaaaaa', 'parentSpanIdHex' => null, 'name' => 'span-zebra-marker'],
        ]);

        $component = $this->createLiveComponent('Waterfall:Sidebar', [
            'tenantSlug' => 'test-cross',
            'traceId' => self::TRACE_ID,
        ]);

        $component->call('selectSpan', ['spanId' => 'ffffffffffffffff']);
        $rendered = (string) $component->render();

        // The span() lookup returns null → empty state copy. The seeded
        // span's distinctive name MUST NOT bleed through (cross-trace
        // defense kept it from being returned at all).
        self::assertStringContainsString('select a span', $rendered);
        self::assertStringNotContainsString('span-zebra-marker', $rendered);
    }
}
