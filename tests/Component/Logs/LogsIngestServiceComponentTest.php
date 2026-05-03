<?php

declare(strict_types=1);

namespace App\Tests\Component\Logs;

use App\Logs\LogsIngestService;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
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

#[CoversClass(LogsIngestService::class)]
final class LogsIngestServiceComponentTest extends TestCase
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
        $writer = new ParquetFileWriter($catalog->latestFor('logs'), Compressions::GZIP);
        $service = new LogsIngestService($writer, $resolver);

        $request = new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [
                    new KeyValueDto('service.name', AnyValueDto::string('checkout')),
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
                            traceId: null,
                            spanId: null,
                            flags: null,
                        ),
                    ],
                )],
            ),
        ]);

        $service->write($request, new Tenant('acme', 'Acme Corp'));

        $expectedPath = $this->tempStorageRoot()
            .'/logs/acme/date=2026-05-03/hour=14/part-COMPONENTTESTULID000000XXXX.parquet';

        self::assertFileExists($expectedPath);
        self::assertFileDoesNotExist($expectedPath.'.tmp');

        $rows = iterator_to_array((new Reader())->read($expectedPath)->values(), false);

        self::assertCount(1, $rows);
        self::assertSame(1714752000000000000, $rows[0]['time_unix_nano']);
        self::assertSame('INFO', $rows[0]['severity_text']);
        self::assertSame('{"stringValue":"hello"}', $rows[0]['body_json']);
        self::assertSame('checkout', $rows[0]['resource_service_name']);
        self::assertSame('app', $rows[0]['scope_name']);
        self::assertSame('1.0', $rows[0]['scope_version']);
    }
}
