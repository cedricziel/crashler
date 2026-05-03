<?php

declare(strict_types=1);

namespace App\Tests\Component\Traces;

use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\ResourceSpansDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Otlp\Dto\SpanEventDto;
use App\Otlp\Dto\SpanStatusDto;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use App\Traces\TracesIngestService;
use Flow\Parquet\ParquetFile\Compressions;
use Flow\Parquet\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(TracesIngestService::class)]
final class TracesIngestServiceComponentTest extends TestCase
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
        $tracesSchema = $catalog->latestFor('traces');
        $writer = new ParquetFileWriter($tracesSchema, Compressions::GZIP);
        $service = new TracesIngestService($writer, $resolver, new AttributeColumnExtractor($tracesSchema));

        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');

        $request = new ExportTraceServiceRequestDto([
            new ResourceSpansDto(
                resourceAttributes: [
                    new KeyValueDto('service.name', AnyValueDto::string('checkout')),
                ],
                scopeSpans: [new ScopeSpansDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    spans: [new SpanDto(
                        traceId: $traceId,
                        spanId: $spanId,
                        parentSpanId: null,
                        traceState: null,
                        flags: null,
                        name: 'GET /orders/:id',
                        kind: 2,
                        startTimeUnixNano: 1714752000000000000,
                        endTimeUnixNano: 1714752000050000000,
                        attributes: [
                            new KeyValueDto('http.request.method', AnyValueDto::string('GET')),
                        ],
                        events: [new SpanEventDto(
                            timeUnixNano: 1714752000000000010,
                            name: 'cache.miss',
                            attributes: [],
                            droppedAttributesCount: 0,
                        )],
                        links: [],
                        status: new SpanStatusDto(2, 'connection refused'),
                        droppedAttributesCount: 0,
                        droppedEventsCount: 0,
                        droppedLinksCount: 0,
                    )],
                )],
            ),
        ]);

        $service->write($request, new Tenant('acme', 'Acme Corp'));

        $expectedPath = $this->tempStorageRoot()
            .'/traces/acme/date=2026-05-03/hour=14/part-COMPONENTTESTULID000000XXXX.parquet';

        self::assertFileExists($expectedPath);
        self::assertFileDoesNotExist($expectedPath.'.tmp');

        $rows = iterator_to_array((new Reader())->read($expectedPath)->values(), false);

        self::assertCount(1, $rows);
        self::assertSame('5b8aa5a2d2c872e8321cf37308d69df2', $rows[0]['trace_id_hex']);
        self::assertSame('051581bf3cb55c13', $rows[0]['span_id_hex']);
        self::assertSame('GET /orders/:id', $rows[0]['name']);
        self::assertSame(2, $rows[0]['kind']);
        self::assertSame('SERVER', $rows[0]['kind_text']);
        self::assertSame(50000000, $rows[0]['duration_nano']);
        self::assertSame(2, $rows[0]['status_code']);
        self::assertSame('ERROR', $rows[0]['status_text']);
        self::assertSame('connection refused', $rows[0]['status_message']);
        self::assertSame('checkout', $rows[0]['resource_service_name']);
        self::assertSame('GET', $rows[0]['http_request_method']);
        self::assertStringContainsString('"name":"cache.miss"', $rows[0]['events_json']);
        self::assertSame('[]', $rows[0]['links_json']);
    }
}
