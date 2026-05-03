<?php

declare(strict_types=1);

namespace App\Tests\Unit\Traces;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\ResourceSpansDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Otlp\Dto\SpanEventDto;
use App\Otlp\Dto\SpanLinkDto;
use App\Otlp\Dto\SpanStatusDto;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\CapturingParquetWriter;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use App\Tests\Support\TracesSchemaFixture;
use App\Traces\TracesIngestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(TracesIngestService::class)]
final class TracesIngestServiceTest extends TestCase
{
    use TempStorageRoot;

    public function testFlattensSpanToBaseRow(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertSame(1, $writer->callCount);
        self::assertNotNull($writer->capturedRows);
        $rows = $writer->capturedRows;
        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertSame('5b8aa5a2d2c872e8321cf37308d69df2', $row['trace_id_hex']);
        self::assertSame('051581bf3cb55c13', $row['span_id_hex']);
        self::assertSame('GET /orders/:id', $row['name']);
        self::assertSame(1714752000000000000, $row['start_time_unix_nano']);
        self::assertSame(1714752000050000000, $row['end_time_unix_nano']);
        self::assertSame(50000000, $row['duration_nano']);
        self::assertSame(2, $row['kind']);
        self::assertSame('SERVER', $row['kind_text']);
        self::assertSame('[]', $row['attributes_json']);
        self::assertSame('[]', $row['events_json']);
        self::assertSame('[]', $row['links_json']);
        self::assertStringContainsString('"service.name"', $row['resource_attributes_json']);
        self::assertStringContainsString('/traces/acme/date=2026-05-03/hour=14/part-', (string) $writer->capturedPath);
    }

    public function testDurationNanoEqualsEndMinusStart(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write($this->buildRequest(start: 100, end: 350), new Tenant('acme', 'Acme Corp'));

        self::assertSame(250, $writer->capturedRows[0]['duration_nano']);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function spanKindProvider(): iterable
    {
        yield 'unspecified' => [0, 'UNSPECIFIED'];
        yield 'internal' => [1, 'INTERNAL'];
        yield 'server' => [2, 'SERVER'];
        yield 'client' => [3, 'CLIENT'];
        yield 'producer' => [4, 'PRODUCER'];
        yield 'consumer' => [5, 'CONSUMER'];
    }

    #[DataProvider('spanKindProvider')]
    public function testSpanKindMapsToText(int $kind, string $expected): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write($this->buildRequest(kind: $kind), new Tenant('acme', 'Acme Corp'));

        self::assertSame($kind, $writer->capturedRows[0]['kind']);
        self::assertSame($expected, $writer->capturedRows[0]['kind_text']);
    }

    public function testStatusPopulatedDecodesToTriple(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write(
            $this->buildRequest(status: new SpanStatusDto(2, 'connection refused')),
            new Tenant('acme', 'Acme Corp'),
        );

        $row = $writer->capturedRows[0];
        self::assertSame(2, $row['status_code']);
        self::assertSame('ERROR', $row['status_text']);
        self::assertSame('connection refused', $row['status_message']);
    }

    public function testStatusUnsetCodeMapsToOkText(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write(
            $this->buildRequest(status: new SpanStatusDto(1, null)),
            new Tenant('acme', 'Acme Corp'),
        );

        $row = $writer->capturedRows[0];
        self::assertSame(1, $row['status_code']);
        self::assertSame('OK', $row['status_text']);
        self::assertNull($row['status_message']);
    }

    public function testMissingStatusYieldsAllNullColumns(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertNull($row['status_code']);
        self::assertNull($row['status_text']);
        self::assertNull($row['status_message']);
    }

    public function testParentSpanIdEmittedAsHexWhenPresent(): void
    {
        $parent = (string) hex2bin('aabbccddeeff1122');
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write($this->buildRequest(parentSpanId: $parent), new Tenant('acme', 'Acme Corp'));

        self::assertSame('aabbccddeeff1122', $writer->capturedRows[0]['parent_span_id_hex']);
    }

    public function testParentSpanIdNullWhenAbsent(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertNull($writer->capturedRows[0]['parent_span_id_hex']);
    }

    public function testTier1ResourcePromotionsLand(): void
    {
        $request = $this->buildRequest(resourceAttributes: [
            new KeyValueDto('service.name', AnyValueDto::string('checkout')),
            new KeyValueDto('service.namespace', AnyValueDto::string('store')),
            new KeyValueDto('service.version', AnyValueDto::string('1.2.3')),
            new KeyValueDto('service.instance.id', AnyValueDto::string('node-7-pid-1234')),
            new KeyValueDto('deployment.environment.name', AnyValueDto::string('prod')),
            new KeyValueDto('host.name', AnyValueDto::string('node-7')),
            new KeyValueDto('telemetry.sdk.language', AnyValueDto::string('php')),
        ]);

        $writer = new CapturingParquetWriter();
        (new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor()))
            ->write($request, new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertSame('checkout', $row['resource_service_name']);
        self::assertSame('store', $row['resource_service_namespace']);
        self::assertSame('1.2.3', $row['resource_service_version']);
        self::assertSame('node-7-pid-1234', $row['resource_service_instance_id']);
        self::assertSame('prod', $row['resource_deployment_environment']);
        self::assertSame('node-7', $row['resource_host_name']);
        self::assertSame('php', $row['resource_telemetry_sdk_language']);
        // Promotion is shadow: the JSON blob still carries every key.
        self::assertStringContainsString('"service.name"', $row['resource_attributes_json']);
    }

    public function testScopeSchemaUrlPromoted(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write(
            $this->buildRequest(scopeSchemaUrl: 'https://opentelemetry.io/schemas/1.30.0'),
            new Tenant('acme', 'Acme Corp'),
        );

        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $writer->capturedRows[0]['scope_schema_url']);
    }

    public function testRecordLevelTier2Promotions(): void
    {
        $attributes = [
            new KeyValueDto('http.request.method', AnyValueDto::string('GET')),
            new KeyValueDto('http.response.status_code', AnyValueDto::int(500)),
            new KeyValueDto('http.route', AnyValueDto::string('/orders/:id')),
            new KeyValueDto('url.scheme', AnyValueDto::string('https')),
            new KeyValueDto('db.system.name', AnyValueDto::string('postgresql')),
            new KeyValueDto('db.collection.name', AnyValueDto::string('orders')),
            new KeyValueDto('messaging.system', AnyValueDto::string('kafka')),
            new KeyValueDto('messaging.destination.name', AnyValueDto::string('orders.created')),
            new KeyValueDto('rpc.service', AnyValueDto::string('OrderService')),
            new KeyValueDto('rpc.method', AnyValueDto::string('PlaceOrder')),
            new KeyValueDto('error.type', AnyValueDto::string('RuntimeException')),
            new KeyValueDto('code.function', AnyValueDto::string('placeOrder')),
            new KeyValueDto('code.namespace', AnyValueDto::string('App\\OrderService')),
        ];

        $writer = new CapturingParquetWriter();
        (new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor()))
            ->write($this->buildRequest(spanAttributes: $attributes), new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertSame('GET', $row['http_request_method']);
        self::assertSame(500, $row['http_response_status_code']);
        self::assertSame('/orders/:id', $row['http_route']);
        self::assertSame('https', $row['url_scheme']);
        self::assertSame('postgresql', $row['db_system_name']);
        self::assertSame('orders', $row['db_collection_name']);
        self::assertSame('kafka', $row['messaging_system']);
        self::assertSame('orders.created', $row['messaging_destination_name']);
        self::assertSame('OrderService', $row['rpc_service']);
        self::assertSame('PlaceOrder', $row['rpc_method']);
        self::assertSame('RuntimeException', $row['error_type']);
        self::assertSame('placeOrder', $row['code_function']);
        self::assertSame('App\\OrderService', $row['code_namespace']);

        // Shadow: original keys still in attributes_json.
        self::assertStringContainsString('"http.request.method"', $row['attributes_json']);
        self::assertStringContainsString('"db.system.name"', $row['attributes_json']);
    }

    public function testEventsAndLinksJsonPopulated(): void
    {
        $event = new SpanEventDto(
            timeUnixNano: 1714752000000000010,
            name: 'cache.miss',
            attributes: [new KeyValueDto('cache.key', AnyValueDto::string('user:42'))],
            droppedAttributesCount: 0,
        );
        $event2 = new SpanEventDto(
            timeUnixNano: 1714752000000000020,
            name: 'cache.fill',
            attributes: [],
            droppedAttributesCount: 0,
        );
        $link = new SpanLinkDto(
            traceId: (string) hex2bin('0123456789abcdef0123456789abcdef'),
            spanId: (string) hex2bin('fedcba9876543210'),
            traceState: null,
            attributes: [],
            droppedAttributesCount: 0,
            flags: null,
        );

        $writer = new CapturingParquetWriter();
        (new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor()))
            ->write($this->buildRequest(events: [$event, $event2], links: [$link]), new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        $events = json_decode($row['events_json'], true, flags: \JSON_THROW_ON_ERROR);
        $links = json_decode($row['links_json'], true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(2, $events);
        self::assertSame('cache.miss', $events[0]['name']);
        self::assertSame('user:42', $events[0]['attributes'][0]['value']['stringValue']);
        self::assertCount(1, $links);
        self::assertSame('0123456789abcdef0123456789abcdef', $links[0]['traceId']);
    }

    public function testEmptyEventsAndLinksJsonAreEmptyArrays(): void
    {
        $writer = new CapturingParquetWriter();
        (new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor()))
            ->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertSame('[]', $writer->capturedRows[0]['events_json']);
        self::assertSame('[]', $writer->capturedRows[0]['links_json']);
    }

    public function testDroppedCountsArePreservedAsZeroNotNull(): void
    {
        $writer = new CapturingParquetWriter();
        (new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor()))
            ->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        $row = $writer->capturedRows[0];
        self::assertSame(0, $row['dropped_attributes_count']);
        self::assertSame(0, $row['dropped_events_count']);
        self::assertSame(0, $row['dropped_links_count']);
    }

    public function testEmptyRequestProducesNoFile(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $service->write(new ExportTraceServiceRequestDto([]), new Tenant('acme', 'Acme Corp'));

        self::assertSame(0, $writer->callCount);
    }

    public function testWrongRequestTypeIsRejected(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());

        $this->expectException(\TypeError::class);

        $service->write(new \stdClass(), new Tenant('acme', 'Acme Corp'));
    }

    public function testCreatesPartitionDirectoryBeforeWriting(): void
    {
        $writer = new class() implements \App\Storage\WritesParquetFiles {
            public ?string $observedDir = null;

            public function writeAndCommit(string $finalPath, iterable $rows): void
            {
                $this->observedDir = \dirname($finalPath);
            }
        };

        $service = new TracesIngestService($writer, $this->resolver(), TracesSchemaFixture::tracesV1Extractor());
        $service->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertNotNull($writer->observedDir);
        self::assertDirectoryExists($writer->observedDir);
    }

    private function resolver(): PartitionPathResolver
    {
        return new PartitionPathResolver(
            clock: new MockClock('2026-05-03 14:37:00 UTC'),
            filenames: new StubFilenameGenerator('TESTFILEULID00000000000000'),
            storageRoot: $this->tempStorageRoot(),
        );
    }

    /**
     * @param list<KeyValueDto>  $resourceAttributes
     * @param list<KeyValueDto>  $spanAttributes
     * @param list<SpanEventDto> $events
     * @param list<SpanLinkDto>  $links
     */
    private function buildRequest(
        int $start = 1714752000000000000,
        int $end = 1714752000050000000,
        int $kind = 2,
        ?SpanStatusDto $status = null,
        ?string $parentSpanId = null,
        array $resourceAttributes = [],
        array $spanAttributes = [],
        array $events = [],
        array $links = [],
        ?string $scopeSchemaUrl = null,
    ): ExportTraceServiceRequestDto {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');

        if ([] === $resourceAttributes) {
            $resourceAttributes = [
                new KeyValueDto('service.name', AnyValueDto::string('checkout')),
            ];
        }

        return new ExportTraceServiceRequestDto([
            new ResourceSpansDto(
                resourceAttributes: $resourceAttributes,
                scopeSpans: [new ScopeSpansDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    spans: [new SpanDto(
                        traceId: $traceId,
                        spanId: $spanId,
                        parentSpanId: $parentSpanId,
                        traceState: null,
                        flags: null,
                        name: 'GET /orders/:id',
                        kind: $kind,
                        startTimeUnixNano: $start,
                        endTimeUnixNano: $end,
                        attributes: $spanAttributes,
                        events: $events,
                        links: $links,
                        status: $status,
                        droppedAttributesCount: 0,
                        droppedEventsCount: 0,
                        droppedLinksCount: 0,
                    )],
                    schemaUrl: $scopeSchemaUrl,
                )],
            ),
        ]);
    }
}
