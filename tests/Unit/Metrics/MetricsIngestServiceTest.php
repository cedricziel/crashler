<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metrics;

use App\Metrics\MetricsIngestService;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExemplarDto;
use App\Otlp\Dto\ExponentialHistogramBucketsDto;
use App\Otlp\Dto\ExponentialHistogramDataPointDto;
use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\HistogramDataPointDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\MetricDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Dto\NumberDataPointDto;
use App\Otlp\Dto\ResourceMetricsDto;
use App\Otlp\Dto\ScopeMetricsDto;
use App\Otlp\Dto\SummaryDataPointDto;
use App\Otlp\Dto\ValueAtQuantileDto;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\CapturingParquetWriter;
use App\Tests\Support\MetricsSchemaFixture;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MetricsIngestService::class)]
final class MetricsIngestServiceTest extends TestCase
{
    use TempStorageRoot;

    public function testFlattensSumDataPointToBaseRow(): void
    {
        $writer = new CapturingParquetWriter();
        $service = $this->service($writer);

        $service->write($this->buildSumRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertSame(1, $writer->callCount);
        $rows = $writer->capturedRows;
        self::assertNotNull($rows);
        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertSame('http.server.requests', $row['metric_name']);
        self::assertSame('SUM', $row['metric_type']);
        self::assertSame(0, $row['metric_type_code']);
        self::assertSame(42, $row['value_int']);
        self::assertNull($row['value_double']);
        self::assertSame(1714752000050000000, $row['time_unix_nano']);
        self::assertSame('[]', $row['attributes_json']);
        self::assertSame('[]', $row['exemplars_json']);
        self::assertStringContainsString('"http.server.requests"', $row['metric_attributes_json']);
        self::assertStringContainsString('"service.name"', $row['resource_attributes_json']);
        self::assertStringContainsString('/metrics/acme/date=2026-05-03/hour=14/part-', (string) $writer->capturedPath);
    }

    public function testNumberDataPointAsDouble(): void
    {
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write(
            $this->buildSumRequest(valueDouble: 1.5, valueInt: null),
            new Tenant('acme', 'Acme Corp'),
        );

        self::assertSame(1.5, $writer->capturedRows[0]['value_double']);
        self::assertNull($writer->capturedRows[0]['value_int']);
    }

    /**
     * @return iterable<string, array{0: MetricType, 1: int, 2: string}>
     */
    public static function metricTypeProvider(): iterable
    {
        yield 'sum' => [MetricType::Sum, 0, 'SUM'];
        yield 'gauge' => [MetricType::Gauge, 1, 'GAUGE'];
        yield 'histogram' => [MetricType::Histogram, 2, 'HISTOGRAM'];
        yield 'exponential_histogram' => [MetricType::ExponentialHistogram, 3, 'EXPONENTIAL_HISTOGRAM'];
        yield 'summary' => [MetricType::Summary, 4, 'SUMMARY'];
    }

    #[DataProvider('metricTypeProvider')]
    public function testMetricTypeDiscriminator(MetricType $type, int $code, string $text): void
    {
        $request = $this->buildRequestForType($type);
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($request, new Tenant('acme', 'Acme Corp'));

        self::assertSame($code, $writer->capturedRows[0]['metric_type_code']);
        self::assertSame($text, $writer->capturedRows[0]['metric_type']);
    }

    public function testHistogramScalarsAndBucketsJson(): void
    {
        $dp = new HistogramDataPointDto(
            startTimeUnixNano: 1, timeUnixNano: 2,
            count: 42, sum: 123.4, min: 0.001, max: 9.99,
            bucketCounts: [10, 20, 12], explicitBounds: [1.0, 5.0],
            attributes: [], exemplars: [], flags: null,
        );
        $metric = $this->metricEnvelope(MetricType::Histogram, histogramDPs: [$dp]);

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($this->wrap($metric), new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertSame(42, $row['count']);
        self::assertSame(123.4, $row['sum']);
        self::assertSame(0.001, $row['min']);
        self::assertSame(9.99, $row['max']);
        self::assertNull($row['value_double']);
        self::assertNull($row['value_int']);
        $buckets = json_decode($row['buckets_json'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['10', '20', '12'], $buckets['bucketCounts']);
    }

    public function testExponentialHistogramFullRoundTrip(): void
    {
        $dp = new ExponentialHistogramDataPointDto(
            startTimeUnixNano: 1, timeUnixNano: 2,
            count: 10, sum: 50.0,
            scale: 2, zeroCount: 1, zeroThreshold: 0.0001,
            positive: new ExponentialHistogramBucketsDto(5, [1, 2, 3]),
            negative: new ExponentialHistogramBucketsDto(-3, [0, 1]),
            min: 0.001, max: 100.0,
            attributes: [], exemplars: [], flags: null,
        );
        $metric = $this->metricEnvelope(MetricType::ExponentialHistogram, exponentialDPs: [$dp]);

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($this->wrap($metric), new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertSame(10, $row['count']);
        self::assertSame(50.0, $row['sum']);
        self::assertSame(0.001, $row['min']);
        self::assertSame(100.0, $row['max']);
        $eh = json_decode($row['exponential_histogram_json'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(2, $eh['scale']);
        self::assertSame('1', $eh['zeroCount']);
        self::assertSame(5, $eh['positive']['offset']);
    }

    public function testSummaryQuantilesJson(): void
    {
        $dp = new SummaryDataPointDto(
            startTimeUnixNano: null, timeUnixNano: 5,
            count: 100, sum: 250.0,
            quantileValues: [
                new ValueAtQuantileDto(0.5, 2.4),
                new ValueAtQuantileDto(0.99, 9.6),
            ],
            attributes: [], flags: null,
        );
        $metric = $this->metricEnvelope(MetricType::Summary, summaryDPs: [$dp]);

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($this->wrap($metric), new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertSame(100, $row['count']);
        self::assertSame(250.0, $row['sum']);
        $q = json_decode($row['quantiles_json'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $q);
        self::assertSame(0.99, $q[1]['quantile']);
    }

    public function testAggregationTemporalityForSum(): void
    {
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write(
            $this->buildSumRequest(temporality: 1),  // DELTA
            new Tenant('acme', 'Acme Corp'),
        );

        $row = $writer->capturedRows[0];
        self::assertSame(1, $row['aggregation_temporality']);
        self::assertSame('DELTA', $row['aggregation_temporality_text']);
    }

    public function testTemporalityNullForGauge(): void
    {
        $request = $this->buildRequestForType(MetricType::Gauge);
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($request, new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertNull($row['aggregation_temporality']);
        self::assertNull($row['aggregation_temporality_text']);
        self::assertNull($row['is_monotonic']);
    }

    public function testIsMonotonicForSum(): void
    {
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write(
            $this->buildSumRequest(isMonotonic: true),
            new Tenant('acme', 'Acme Corp'),
        );

        self::assertTrue($writer->capturedRows[0]['is_monotonic']);
    }

    public function testTier1ResourcePromotionsLand(): void
    {
        $request = $this->buildSumRequest(resourceAttributes: [
            new KeyValueDto('service.name', AnyValueDto::string('checkout')),
            new KeyValueDto('service.namespace', AnyValueDto::string('store')),
            new KeyValueDto('deployment.environment.name', AnyValueDto::string('prod')),
            new KeyValueDto('host.name', AnyValueDto::string('node-7')),
        ]);

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($request, new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertSame('checkout', $row['resource_service_name']);
        self::assertSame('store', $row['resource_service_namespace']);
        self::assertSame('prod', $row['resource_deployment_environment']);
        self::assertSame('node-7', $row['resource_host_name']);
        // Shadow: original keys still in resource_attributes_json.
        self::assertStringContainsString('"service.name"', $row['resource_attributes_json']);
    }

    public function testScopeSchemaUrlPromoted(): void
    {
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write(
            $this->buildSumRequest(scopeSchemaUrl: 'https://opentelemetry.io/schemas/1.30.0'),
            new Tenant('acme', 'Acme Corp'),
        );

        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $writer->capturedRows[0]['scope_schema_url']);
    }

    public function testExemplarsPopulated(): void
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');
        $exemplar = new ExemplarDto(
            timeUnixNano: 1714752000000000010,
            valueDouble: 1.5,
            valueInt: null,
            traceId: $traceId,
            spanId: $spanId,
            filteredAttributes: [],
        );
        $request = $this->buildSumRequest(exemplars: [$exemplar]);

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($request, new Tenant('acme', 'Acme Corp'));

        $exemplars = json_decode($writer->capturedRows[0]['exemplars_json'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $exemplars);
        self::assertSame('5b8aa5a2d2c872e8321cf37308d69df2', $exemplars[0]['traceId']);
    }

    public function testEmptyExemplarsJsonIsEmptyArrayNotNull(): void
    {
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($this->buildSumRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertSame('[]', $writer->capturedRows[0]['exemplars_json']);
    }

    public function testMetricEnvelopeRoundTrip(): void
    {
        $writer = new CapturingParquetWriter();
        $this->service($writer)->write(
            $this->buildSumRequest(name: 'http.req', unit: 'ms', description: 'duration'),
            new Tenant('acme', 'Acme Corp'),
        );

        $envelope = json_decode($writer->capturedRows[0]['metric_attributes_json'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('http.req', $envelope['name']);
        self::assertSame('ms', $envelope['unit']);
        self::assertSame('duration', $envelope['description']);
        self::assertSame('SUM', $envelope['metricType']);
    }

    public function testMetricWithEmptyDataPointsProducesNoRows(): void
    {
        $metric = new MetricDto(
            name: 'empty',
            unit: null,
            description: null,
            type: MetricType::Sum,
            aggregationTemporality: 2,
            isMonotonic: true,
            numberDataPoints: [],
            histogramDataPoints: [],
            exponentialHistogramDataPoints: [],
            summaryDataPoints: [],
        );

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($this->wrap($metric), new Tenant('acme', 'Acme Corp'));

        self::assertSame(0, $writer->callCount);
    }

    public function testRequestWithOnlyEmptyMetricsProducesNoFile(): void
    {
        $request = new ExportMetricsServiceRequestDto([
            new ResourceMetricsDto(
                resourceAttributes: [],
                scopeMetrics: [new ScopeMetricsDto(
                    scopeName: null, scopeVersion: null,
                    metrics: [],
                )],
            ),
        ]);

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($request, new Tenant('acme', 'Acme Corp'));

        self::assertSame(0, $writer->callCount);
    }

    public function testWrongRequestTypeIsRejected(): void
    {
        $writer = new CapturingParquetWriter();
        $this->expectException(\TypeError::class);
        $this->service($writer)->write(new \stdClass(), new Tenant('acme', 'Acme Corp'));
    }

    public function testMultipleDataPointsBecomeMultipleRows(): void
    {
        $dp1 = new NumberDataPointDto(null, 1, null, 1, [], [], null);
        $dp2 = new NumberDataPointDto(null, 2, null, 2, [], [], null);
        $metric = new MetricDto(
            name: 'm',
            unit: null,
            description: null,
            type: MetricType::Sum,
            aggregationTemporality: 2,
            isMonotonic: true,
            numberDataPoints: [$dp1, $dp2],
            histogramDataPoints: [],
            exponentialHistogramDataPoints: [],
            summaryDataPoints: [],
        );

        $writer = new CapturingParquetWriter();
        $this->service($writer)->write($this->wrap($metric), new Tenant('acme', 'Acme Corp'));

        self::assertCount(2, $writer->capturedRows);
        self::assertSame(1, $writer->capturedRows[0]['value_int']);
        self::assertSame(2, $writer->capturedRows[1]['value_int']);
    }

    private function service(CapturingParquetWriter $writer): MetricsIngestService
    {
        return new MetricsIngestService(
            $writer,
            new PartitionPathResolver(
                clock: new MockClock('2026-05-03 14:37:00 UTC'),
                filenames: new StubFilenameGenerator('TESTFILEULID00000000000000'),
                storageRoot: $this->tempStorageRoot(),
            ),
            MetricsSchemaFixture::metricsV1Extractor(),
        );
    }

    /**
     * @param list<KeyValueDto> $resourceAttributes
     * @param list<KeyValueDto> $dpAttributes
     * @param list<ExemplarDto> $exemplars
     */
    private function buildSumRequest(
        string $name = 'http.server.requests',
        ?string $unit = '1',
        ?string $description = 'request count',
        ?float $valueDouble = null,
        ?int $valueInt = 42,
        int $temporality = 2,
        bool $isMonotonic = true,
        array $resourceAttributes = [],
        array $dpAttributes = [],
        array $exemplars = [],
        ?string $scopeSchemaUrl = null,
    ): ExportMetricsServiceRequestDto {
        if ([] === $resourceAttributes) {
            $resourceAttributes = [new KeyValueDto('service.name', AnyValueDto::string('checkout'))];
        }

        $dp = new NumberDataPointDto(
            startTimeUnixNano: 1714752000000000000,
            timeUnixNano: 1714752000050000000,
            valueDouble: $valueDouble,
            valueInt: $valueInt,
            attributes: $dpAttributes,
            exemplars: $exemplars,
            flags: null,
        );

        $metric = new MetricDto(
            name: $name,
            unit: $unit,
            description: $description,
            type: MetricType::Sum,
            aggregationTemporality: $temporality,
            isMonotonic: $isMonotonic,
            numberDataPoints: [$dp],
            histogramDataPoints: [],
            exponentialHistogramDataPoints: [],
            summaryDataPoints: [],
        );

        return new ExportMetricsServiceRequestDto([
            new ResourceMetricsDto(
                resourceAttributes: $resourceAttributes,
                scopeMetrics: [new ScopeMetricsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    metrics: [$metric],
                    schemaUrl: $scopeSchemaUrl,
                )],
            ),
        ]);
    }

    private function buildRequestForType(MetricType $type): ExportMetricsServiceRequestDto
    {
        return $this->wrap($this->metricEnvelope($type, defaultDataPoint: true));
    }

    /**
     * @param list<NumberDataPointDto>               $numberDPs
     * @param list<HistogramDataPointDto>            $histogramDPs
     * @param list<ExponentialHistogramDataPointDto> $exponentialDPs
     * @param list<SummaryDataPointDto>              $summaryDPs
     */
    private function metricEnvelope(
        MetricType $type,
        array $numberDPs = [],
        array $histogramDPs = [],
        array $exponentialDPs = [],
        array $summaryDPs = [],
        bool $defaultDataPoint = false,
    ): MetricDto {
        if ($defaultDataPoint) {
            switch ($type) {
                case MetricType::Sum:
                case MetricType::Gauge:
                    $numberDPs = [new NumberDataPointDto(null, 1, null, 1, [], [], null)];
                    break;
                case MetricType::Histogram:
                    $histogramDPs = [new HistogramDataPointDto(null, 1, 0, null, null, null, [], [], [], [], null)];
                    break;
                case MetricType::ExponentialHistogram:
                    $exponentialDPs = [new ExponentialHistogramDataPointDto(
                        null, 1, 0, null, 0, 0, null, null, null, null, null, [], [], null,
                    )];
                    break;
                case MetricType::Summary:
                    $summaryDPs = [new SummaryDataPointDto(null, 1, 0, 0.0, [], [], null)];
                    break;
            }
        }

        return new MetricDto(
            name: 'm',
            unit: null,
            description: null,
            type: $type,
            aggregationTemporality: MetricType::Gauge === $type || MetricType::Summary === $type ? null : 2,
            isMonotonic: MetricType::Sum === $type ? false : null,
            numberDataPoints: $numberDPs,
            histogramDataPoints: $histogramDPs,
            exponentialHistogramDataPoints: $exponentialDPs,
            summaryDataPoints: $summaryDPs,
        );
    }

    private function wrap(MetricDto $metric): ExportMetricsServiceRequestDto
    {
        return new ExportMetricsServiceRequestDto([
            new ResourceMetricsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeMetrics: [new ScopeMetricsDto(
                    scopeName: 'app', scopeVersion: '1.0',
                    metrics: [$metric],
                )],
            ),
        ]);
    }
}
