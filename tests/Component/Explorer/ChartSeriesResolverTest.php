<?php

declare(strict_types=1);

namespace App\Tests\Component\Explorer;

use App\Explorer\ChartSeriesResolver;
use App\Read\Criteria\TimeWindow;
use App\Tests\Support\SeedsParquetLogs;
use App\Tests\Support\TempStorageRoot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins the ChartSeriesResolver's actual scan + bucket pipeline against
 * seeded parquet rows. The ChartDataEndpointTest covers auth + empty
 * payload shape over HTTP; this test covers the populated path the
 * endpoint can't easily reach in a WebTestCase (which has no
 * TempStorageRoot wiring).
 */
final class ChartSeriesResolverTest extends KernelTestCase
{
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

    public function testReturnsXAxisAndOneSeriesPerGroup(): void
    {
        // Seed: two services with distinct row counts.
        $window = $this->seedLogs('test-chart', ['a', 'b', 'c'], service: 'checkout');
        $this->seedLogs('test-chart', ['x'], service: 'payments', atIso: '2026-05-09 14:30:01 UTC');

        $resolver = self::getContainer()->get(ChartSeriesResolver::class);
        $payload = $resolver->series(
            'test-chart',
            'logs',
            new TimeWindow($window['since_ns'], $window['until_ns']),
            'resource_service_name',
        );

        self::assertArrayHasKey('x', $payload);
        self::assertArrayHasKey('series', $payload);
        self::assertNotEmpty($payload['x']);
        // One series per group (capped at MAX_SERIES = 8).
        self::assertCount(2, $payload['series']);

        $byLabel = [];
        foreach ($payload['series'] as $s) {
            $byLabel[$s['label']] = array_sum($s['values']);
        }
        self::assertSame(3, $byLabel['checkout']);
        self::assertSame(1, $byLabel['payments']);
        // Every series's `values` array length must match `x` length so
        // chart_controller's parallel-array contract holds.
        foreach ($payload['series'] as $s) {
            self::assertCount(\count($payload['x']), $s['values']);
        }
    }

    public function testEmptyPartitionReturnsBucketsButNoSeries(): void
    {
        $resolver = self::getContainer()->get(ChartSeriesResolver::class);
        $payload = $resolver->series(
            'never-seeded',
            'logs',
            new TimeWindow(1_000_000_000, 60_000_000_000),
            'resource_service_name',
        );

        self::assertNotEmpty($payload['x']);
        self::assertSame([], $payload['series']);
    }
}
