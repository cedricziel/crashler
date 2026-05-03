<?php

declare(strict_types=1);

namespace App\Tests\Component\Metrics;

use App\Metrics\MetricsIngestService;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\HistogramDataPointDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\MetricDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Dto\ResourceMetricsDto;
use App\Otlp\Dto\ScopeMetricsDto;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use Flow\Parquet\ParquetFile\Compressions;
use Flow\Parquet\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MetricsIngestService::class)]
final class MetricsIngestServiceComponentTest extends TestCase
{
    use TempStorageRoot;

    public function testEndToEndWritesReadableParquetFileAtExpectedPath(): void
    {
        $clock = new MockClock('2026-05-03 14:37:00 UTC');
        $filenames = new StubFilenameGenerator('COMPONENTTESTULID000000XXXX');
        $resolver = new PartitionPathResolver(
            clock: $clock,
            filenames: $filenames,
            storageRoot: $this->tempStorageRoot(),
        );

        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $metricsSchema = $catalog->latestFor('metrics');
        $writer = new ParquetFileWriter($metricsSchema, Compressions::GZIP);
        $service = new MetricsIngestService($writer, $resolver, new AttributeColumnExtractor($metricsSchema));

        $dp = new HistogramDataPointDto(
            startTimeUnixNano: 1714752000000000000,
            timeUnixNano: 1714752000050000000,
            count: 42,
            sum: 123.4,
            min: 0.001,
            max: 9.99,
            bucketCounts: [10, 20, 12],
            explicitBounds: [1.0, 5.0],
            attributes: [
                new KeyValueDto('http.request.method', AnyValueDto::string('GET')),
            ],
            exemplars: [],
            flags: null,
        );

        $request = new ExportMetricsServiceRequestDto([
            new ResourceMetricsDto(
                resourceAttributes: [
                    new KeyValueDto('service.name', AnyValueDto::string('checkout')),
                ],
                scopeMetrics: [new ScopeMetricsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    metrics: [new MetricDto(
                        name: 'http.duration',
                        unit: 'ms',
                        description: 'request duration',
                        type: MetricType::Histogram,
                        aggregationTemporality: 2,
                        isMonotonic: null,
                        numberDataPoints: [],
                        histogramDataPoints: [$dp],
                        exponentialHistogramDataPoints: [],
                        summaryDataPoints: [],
                    )],
                )],
            ),
        ]);

        $service->write($request, new Tenant('acme', 'Acme Corp'));

        $expectedPath = $this->tempStorageRoot()
            .'/metrics/acme/date=2026-05-03/hour=14/part-COMPONENTTESTULID000000XXXX.parquet';

        self::assertFileExists($expectedPath);
        self::assertFileDoesNotExist($expectedPath.'.tmp');

        $rows = iterator_to_array((new Reader())->read($expectedPath)->values(), false);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('http.duration', $row['metric_name']);
        self::assertSame('HISTOGRAM', $row['metric_type']);
        self::assertSame(2, $row['metric_type_code']);
        self::assertSame(42, $row['count']);
        self::assertSame(123.4, $row['sum']);
        self::assertSame(0.001, $row['min']);
        self::assertSame(9.99, $row['max']);
        self::assertSame('checkout', $row['resource_service_name']);
        self::assertStringContainsString('"bucketCounts"', $row['buckets_json']);
        self::assertSame('[]', $row['exemplars_json']);
    }
}
