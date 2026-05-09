<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Compute;

use App\Logs\LogsIngestService;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Compute\RowGroupSkipper;
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

#[CoversClass(RowGroupSkipper::class)]
final class RowGroupSkipperTest extends TestCase
{
    use TempStorageRoot;

    public function testReturnsFalseWhenColumnAbsentFromSchema(): void
    {
        // Logs schema does not have `http_response_status_code` (that's a
        // traces column). A predicate referencing it must be treated as
        // indeterminate — RowGroupSkipper falls through to row-by-row.
        [$rowGroup, $schema] = $this->openLogsRowGroup([
            ['service' => 'checkout', 'severity' => 9, 'body' => 'a', 'time_unix_nano' => 1_778_421_600_000_000_000],
        ]);

        $skipper = new RowGroupSkipper();
        $canSkip = $skipper->canSkip(
            $rowGroup,
            $schema,
            [new ColumnGreaterEqual('http_response_status_code', 500)],
        );

        self::assertFalse($canSkip, 'predicate on column absent from schema must be indeterminate');
    }

    public function testReturnsFalseForStringEqualsAgainstNumericStats(): void
    {
        // Numeric stats don't refute a string ColumnEquals predicate.
        [$rowGroup, $schema] = $this->openLogsRowGroup([
            ['service' => 'checkout', 'severity' => 9, 'body' => 'a', 'time_unix_nano' => 1_778_421_600_000_000_000],
        ]);

        $skipper = new RowGroupSkipper();
        $canSkip = $skipper->canSkip(
            $rowGroup,
            $schema,
            [new ColumnEquals('resource_service_name', 'no-such-service')],
        );

        self::assertFalse($canSkip, 'string ColumnEquals must not refute via numeric stats');
    }

    public function testRefutesWhenNumericRangeIsDisjoint(): void
    {
        // Sanity check: when the predicate's range is provably disjoint from
        // the row group's [min, max], the skipper says skip=true.
        $rowTime = 1_778_421_600_000_000_000;
        [$rowGroup, $schema] = $this->openLogsRowGroup([
            ['service' => 'checkout', 'severity' => 9, 'body' => 'a', 'time_unix_nano' => $rowTime],
        ]);

        $skipper = new RowGroupSkipper();
        $canSkip = $skipper->canSkip(
            $rowGroup,
            $schema,
            [new ColumnInRange('time_unix_nano', $rowTime + 1_000_000_000, $rowTime + 2_000_000_000)],
        );

        self::assertTrue($canSkip, 'window strictly after the group should refute');
    }

    /**
     * @param list<array{service: string, severity: int, body: string, time_unix_nano: int}> $records
     *
     * @return array{0: \Flow\Parquet\ParquetFile\RowGroup, 1: \Flow\Parquet\ParquetFile\Schema}
     */
    private function openLogsRowGroup(array $records): array
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 4).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $writer = new ParquetFileWriter($logsSchema, Compressions::GZIP);
        $extractor = new AttributeColumnExtractor($logsSchema);

        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $resolver = new PartitionPathResolver(
            $clock,
            new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))),
            $this->tempStorageRoot(),
        );

        $logRecords = [];
        foreach ($records as $r) {
            $logRecords[] = new LogRecordDto(
                timeUnixNano: $r['time_unix_nano'],
                observedTimeUnixNano: null,
                severityNumber: $r['severity'],
                severityText: 'INFO',
                body: AnyValueDto::string($r['body']),
                attributes: [],
                droppedAttributesCount: 0,
                traceId: null,
                spanId: null,
                flags: null,
            );
        }

        $service = new LogsIngestService($writer, $resolver, $extractor);
        $service->write(new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string($records[0]['service']))],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: $logRecords,
                )],
            ),
        ]), new Tenant('acme', 'acme'));

        $files = glob($this->tempStorageRoot().'/logs/acme/date=2026-05-09/hour=14/part-*.parquet');
        self::assertNotFalse($files);
        self::assertCount(1, $files);

        $reader = new Reader();
        $parquetFile = $reader->read($files[0]);
        $rowGroups = $parquetFile->metadata()->rowGroups()->all();
        self::assertCount(1, $rowGroups);

        return [$rowGroups[0], $parquetFile->schema()];
    }
}
