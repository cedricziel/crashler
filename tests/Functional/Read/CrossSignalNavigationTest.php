<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Logs\LogsIngestService;
use App\Metrics\MetricsIngestService;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExemplarDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\MetricDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Dto\NumberDataPointDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ResourceMetricsDto;
use App\Otlp\Dto\ResourceSpansDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Otlp\Dto\ScopeMetricsDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use App\Traces\TracesIngestService;
use Flow\Parquet\ParquetFile\Compressions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * End-to-end cross-signal navigation: ingest a trace + matching log +
 * matching metric (all sharing the same trace_id), then exercise the
 * HATEOAS links from the trace-by-id response and verify the follow-up
 * GETs land on the right data.
 */
final class CrossSignalNavigationTest extends KernelTestCase
{
    use HasBrowser;
    use TempStorageRoot;

    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';
    private const string TRACE_HEX = '5b8aa5a2d2c872e8321cf37308d69df2';
    private const string SPAN_HEX = '051581bf3cb55c13';

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testTraceLinksReachLogsAndMetricsThatReferenceIt(): void
    {
        $this->writeFixtures('test-tenant');

        // Step 1: get the trace by ID
        $traceResponse = $this->browser()
            ->get('/v1/traces/'.self::TRACE_HEX.'?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $traceBody = json_decode((string) $traceResponse->client()->getResponse()->getContent(), true);

        // Validate the cross-signal links are present and well-formed
        $logsLink = $traceBody['_links']['logs'];
        $metricsLink = $traceBody['_links']['metricsWithExemplars'];
        self::assertStringStartsWith('/v1/logs?traceId='.self::TRACE_HEX.'&', $logsLink);
        self::assertStringStartsWith('/v1/metrics?exemplarTraceId='.self::TRACE_HEX.'&', $metricsLink);

        // Step 2: follow the logs link — should return logs from this trace
        $logsResponse = $this->browser()
            ->get($logsLink, [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $logsBody = json_decode((string) $logsResponse->client()->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $logsBody['totalItems'] ?? 0, 'logs link should return at least one log from the trace');

        // Step 3: follow the metricsWithExemplars link — metrics whose
        // exemplars reference this trace
        $metricsResponse = $this->browser()
            ->get($metricsLink, [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $metricsBody = json_decode((string) $metricsResponse->client()->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $metricsBody['totalItems'] ?? 0, 'metricsWithExemplars link should return at least one metric whose exemplars reference the trace');
    }

    private function writeFixtures(string $tenant): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        // Event timestamps placed inside the same wall-clock hour as the
        // MockClock so PartitionPathResolver writes files to the same
        // date+hour partition the read query will scan.
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;
        $traceStart = $clockUnixNano;
        $traceEnd = $clockUnixNano + 50_000_000;
        $logTime = $clockUnixNano + 10_000_000;
        $metricTime = $clockUnixNano + 20_000_000;
        $traceBytes = (string) hex2bin(self::TRACE_HEX);
        $spanBytes = (string) hex2bin(self::SPAN_HEX);
        $tenantObj = new Tenant($tenant, $tenant);

        // 1) Write a trace with one span carrying the canonical IDs
        $tracesSchema = $catalog->latestFor('traces');
        $tracesService = new TracesIngestService(
            new ParquetFileWriter($tracesSchema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
            new AttributeColumnExtractor($tracesSchema),
        );
        $tracesService->write(new ExportTraceServiceRequestDto([
            new ResourceSpansDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeSpans: [new ScopeSpansDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    spans: [new SpanDto(
                        traceId: $traceBytes, spanId: $spanBytes,
                        parentSpanId: null, traceState: null, flags: null,
                        name: 'GET /orders/:id', kind: 2,
                        startTimeUnixNano: $traceStart,
                        endTimeUnixNano: $traceEnd,
                        attributes: [], events: [], links: [], status: null,
                        droppedAttributesCount: 0, droppedEventsCount: 0, droppedLinksCount: 0,
                    )],
                )],
            ),
        ]), $tenantObj);

        // 2) Write a log record carrying the same trace_id + span_id
        $logsSchema = $catalog->latestFor('logs');
        $logsService = new LogsIngestService(
            new ParquetFileWriter($logsSchema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
            new AttributeColumnExtractor($logsSchema),
        );
        $logsService->write(new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: [new LogRecordDto(
                        timeUnixNano: $logTime,
                        observedTimeUnixNano: null,
                        severityNumber: 17,
                        severityText: 'ERROR',
                        body: AnyValueDto::string('span emitted this log'),
                        attributes: [],
                        droppedAttributesCount: 0,
                        traceId: $traceBytes,
                        spanId: $spanBytes,
                        flags: null,
                    )],
                )],
            ),
        ]), $tenantObj);

        // 3) Write a metric data-point whose exemplar references the trace
        $metricsSchema = $catalog->latestFor('metrics');
        $metricsService = new MetricsIngestService(
            new ParquetFileWriter($metricsSchema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
            new AttributeColumnExtractor($metricsSchema),
        );
        $exemplar = new ExemplarDto(
            timeUnixNano: $metricTime,
            valueDouble: 0.05,
            valueInt: null,
            traceId: $traceBytes,
            spanId: $spanBytes,
            filteredAttributes: [],
        );
        $metricsService->write(new ExportMetricsServiceRequestDto([
            new ResourceMetricsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeMetrics: [new ScopeMetricsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    metrics: [new MetricDto(
                        name: 'http.server.request.duration',
                        unit: 's',
                        description: null,
                        type: MetricType::Sum,
                        aggregationTemporality: 2,
                        isMonotonic: true,
                        numberDataPoints: [new NumberDataPointDto(
                            startTimeUnixNano: $traceStart,
                            timeUnixNano: $metricTime,
                            valueDouble: 0.05,
                            valueInt: null,
                            attributes: [],
                            exemplars: [$exemplar],
                            flags: null,
                        )],
                        histogramDataPoints: [],
                        exponentialHistogramDataPoints: [],
                        summaryDataPoints: [],
                    )],
                )],
            ),
        ]), $tenantObj);
    }
}
