<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logs;

use App\Logs\LogsIngestService;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\CapturingParquetWriter;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(LogsIngestService::class)]
final class LogsIngestServiceTest extends TestCase
{
    use TempStorageRoot;

    public function testFlattensRequestToRowsAndDispatchesToWriter(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new LogsIngestService($writer, $this->resolver());

        $service->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertSame(1, $writer->callCount);
        self::assertNotNull($writer->capturedRows);
        self::assertNotNull($writer->capturedPath);
        $rows = $writer->capturedRows;
        self::assertCount(2, $rows);

        // Resource attributes denormalized onto every row.
        self::assertSame(
            $rows[0]['resource_attributes_json'],
            $rows[1]['resource_attributes_json'],
        );

        // service.name extracted from resource attributes.
        self::assertSame('checkout', $rows[0]['resource_service_name']);
        self::assertSame('checkout', $rows[1]['resource_service_name']);

        // Scope name/version copied.
        self::assertSame('app', $rows[0]['scope_name']);
        self::assertSame('1.0', $rows[0]['scope_version']);

        // body_json preserves AnyValue variant.
        self::assertSame('{"stringValue":"hello"}', $rows[0]['body_json']);
        self::assertSame('{"intValue":"42"}', $rows[1]['body_json']);

        // trace_id_hex / span_id_hex encoded as lowercase hex.
        self::assertSame('5b8aa5a2d2c872e8321cf37308d69df2', $rows[0]['trace_id_hex']);
        self::assertSame('051581bf3cb55c13', $rows[0]['span_id_hex']);
        self::assertNull($rows[1]['trace_id_hex']);
        self::assertNull($rows[1]['span_id_hex']);

        // timestamps preserved.
        self::assertSame(1714752000000000000, $rows[0]['time_unix_nano']);
        self::assertSame(1714752000000000001, $rows[1]['time_unix_nano']);

        // attributes_json encodes per-record attributes as OTLP wire form.
        self::assertSame('[]', $rows[0]['attributes_json']);
        self::assertSame(
            '[{"key":"http.status_code","value":{"intValue":"500"}}]',
            $rows[1]['attributes_json'],
        );

        // path resolved via PartitionPathResolver under the configured root.
        self::assertStringContainsString('/logs/acme/date=2026-05-03/hour=14/part-', $writer->capturedPath);
    }

    public function testServiceNameNullWhenAbsentInResourceAttributes(): void
    {
        $request = new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: null,
                    scopeVersion: null,
                    logRecords: [new LogRecordDto(
                        timeUnixNano: 1,
                        observedTimeUnixNano: null,
                        severityNumber: null,
                        severityText: null,
                        body: null,
                        attributes: [],
                        droppedAttributesCount: 0,
                        traceId: null,
                        spanId: null,
                        flags: null,
                    )],
                )],
            ),
        ]);

        $writer = new CapturingParquetWriter();
        (new LogsIngestService($writer, $this->resolver()))->write($request, new Tenant('acme', 'Acme Corp'));

        self::assertNotNull($writer->capturedRows);
        $rows = $writer->capturedRows;
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['resource_service_name']);
        self::assertNull($rows[0]['scope_name']);
        self::assertNull($rows[0]['scope_version']);
        self::assertNull($rows[0]['body_json'], 'absent body must serialize as NULL, distinct from empty AnyValue {}');
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

        $service = new LogsIngestService($writer, $this->resolver());
        $service->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));

        self::assertNotNull($writer->observedDir);
        self::assertDirectoryExists($writer->observedDir);
    }

    public function testWriterFailureIsRethrown(): void
    {
        $writer = new CapturingParquetWriter();
        $writer->failNextCallWith(new \RuntimeException('disk full'));

        $service = new LogsIngestService($writer, $this->resolver());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disk full');

        $service->write($this->buildRequest(), new Tenant('acme', 'Acme Corp'));
    }

    public function testEmptyRequestProducesNoFile(): void
    {
        $writer = new CapturingParquetWriter();
        $service = new LogsIngestService($writer, $this->resolver());

        $service->write(new ExportLogsServiceRequestDto([]), new Tenant('acme', 'Acme Corp'));

        self::assertSame(0, $writer->callCount);
    }

    private function resolver(): PartitionPathResolver
    {
        return new PartitionPathResolver(
            clock: new MockClock('2026-05-03 14:37:00 UTC'),
            filenames: new StubFilenameGenerator('TESTFILEULID00000000000000'),
            storageRoot: $this->tempStorageRoot(),
        );
    }

    private function buildRequest(): ExportLogsServiceRequestDto
    {
        $traceId = hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = hex2bin('051581bf3cb55c13');
        self::assertNotFalse($traceId);
        self::assertNotFalse($spanId);

        return new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [
                    new KeyValueDto('service.name', AnyValueDto::string('checkout')),
                    new KeyValueDto('host.name', AnyValueDto::string('node-1')),
                ],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: [
                        new LogRecordDto(
                            timeUnixNano: 1714752000000000000,
                            observedTimeUnixNano: null,
                            severityNumber: 9,
                            severityText: 'INFO',
                            body: AnyValueDto::string('hello'),
                            attributes: [],
                            droppedAttributesCount: 0,
                            traceId: $traceId,
                            spanId: $spanId,
                            flags: 0,
                        ),
                        new LogRecordDto(
                            timeUnixNano: 1714752000000000001,
                            observedTimeUnixNano: null,
                            severityNumber: 17,
                            severityText: 'ERROR',
                            body: AnyValueDto::int(42),
                            attributes: [
                                new KeyValueDto('http.status_code', AnyValueDto::int(500)),
                            ],
                            droppedAttributesCount: 0,
                            traceId: null,
                            spanId: null,
                            flags: null,
                        ),
                    ],
                )],
            ),
        ]);
    }
}
