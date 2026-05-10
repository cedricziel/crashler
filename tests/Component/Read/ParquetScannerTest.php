<?php

declare(strict_types=1);

namespace App\Tests\Component\Read;

use App\Logs\LogsIngestService;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Read\Compute\ParquetScanner;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\JsonAttributeEquals;
use App\Read\Compute\Predicates\Predicate;
use App\Read\Compute\ScanIoException;
use App\Read\Compute\ScanTimeoutException;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use Flow\Parquet\ParquetFile\Compressions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ParquetScanner::class)]
final class ParquetScannerTest extends TestCase
{
    use TempStorageRoot;

    public function testScanReturnsRowsFromSyntheticFixture(): void
    {
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'hello'],
            ['service' => 'checkout', 'severity' => 17, 'body' => 'oops'],
            ['service' => 'payments', 'severity' => 9, 'body' => 'fine'],
        ]);

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [new ColumnEquals('resource_service_name', 'checkout')], limit: 100);

        self::assertCount(2, $result->rows);
        self::assertSame('checkout', $result->rows[0]['resource_service_name']);
        self::assertSame('checkout', $result->rows[1]['resource_service_name']);
        self::assertFalse($result->hasMore);
    }

    public function testEmptyResultWhenNoRowsMatch(): void
    {
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'a'],
        ]);

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [new ColumnEquals('resource_service_name', 'no-such-service')], limit: 100);

        self::assertSame([], $result->rows);
        self::assertNull($result->position);
        self::assertFalse($result->hasMore);
    }

    public function testEarlyExitOnLimit(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; ++$i) {
            $rows[] = ['service' => 'checkout', 'severity' => 9, 'body' => "line-$i"];
        }
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', $rows);

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [new ColumnEquals('resource_service_name', 'checkout')], limit: 10);

        self::assertCount(10, $result->rows);
        self::assertTrue($result->hasMore, 'with 50 matching rows and limit=10, hasMore must be true');
        self::assertNotNull($result->position);
    }

    public function testTierOrderedEvaluation(): void
    {
        // Tier-2 service filter rejects 100% of rows; the tier-4 attribute
        // filter never gets to run. With both predicates supplied unsorted,
        // the scanner must reorder them so the cheap one runs first.
        $rows = [];
        for ($i = 0; $i < 100; ++$i) {
            $rows[] = ['service' => 'unrelated', 'severity' => 9, 'body' => 'noise'];
        }
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', $rows);

        // Pass an expensive predicate first; scanner must sort it after the
        // cheap one.
        $expensive = new JsonAttributeEquals('attributes_json', 'never', 'matches');
        $cheap = new ColumnEquals('resource_service_name', 'no-such-service');

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [$expensive, $cheap], limit: 100);

        self::assertSame([], $result->rows);
    }

    public function testUlidOrderedFileIteration(): void
    {
        // Write three files into the same partition with controlled ULID
        // values so the scanner is guaranteed to read them in A < B < C order.
        $partitionDir = $this->tempStorageRoot().'/logs/acme/date=2026-05-09/hour=14';
        mkdir($partitionDir, 0o750, true);

        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $writer = new ParquetFileWriter($logsSchema, Compressions::GZIP);
        $extractor = new AttributeColumnExtractor($logsSchema);

        $clock = new MockClock('2026-05-09 14:30:00 UTC');

        foreach (['01AAAA', '01BBBB', '01CCCC'] as $i => $ulidPrefix) {
            $resolver = new PartitionPathResolver(
                $clock,
                new StubFilenameGenerator($ulidPrefix.'0000000000000000000000'),
                $this->tempStorageRoot(),
            );
            $service = new LogsIngestService($writer, $resolver, $extractor);
            $request = $this->buildLogsRequest([['service' => "svc-{$i}", 'severity' => 9, 'body' => "from-{$ulidPrefix}"]]);
            $service->write($request, new Tenant('acme', 'Acme Corp'));
        }

        $scanner = new ParquetScanner($clock, executionTimeoutSeconds: 10);
        $result = $scanner->scan(["$partitionDir/part-*.parquet"], [], limit: 100);

        self::assertCount(3, $result->rows);
        self::assertSame('svc-0', $result->rows[0]['resource_service_name']);
        self::assertSame('svc-1', $result->rows[1]['resource_service_name']);
        self::assertSame('svc-2', $result->rows[2]['resource_service_name']);
    }

    public function testCorruptParquetFileThrowsScanIoException(): void
    {
        $dir = $this->tempStorageRoot().'/logs/acme/date=2026-05-09/hour=14';
        mkdir($dir, 0o750, true);
        // Looks like a parquet file but isn't
        file_put_contents("$dir/part-CORRUPT.parquet", 'not a parquet file at all');

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);

        $this->expectException(ScanIoException::class);
        // Must NOT leak the absolute filesystem path (var/ leakage check)
        $scanner->scan(["$dir/part-*.parquet"], [], limit: 100);
    }

    public function testCorruptParquetMessageMasksAbsolutePath(): void
    {
        $dir = $this->tempStorageRoot().'/logs/acme/date=2026-05-09/hour=14';
        mkdir($dir, 0o750, true);
        file_put_contents("$dir/part-CORRUPT.parquet", 'not parquet');

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);

        try {
            $scanner->scan(["$dir/part-*.parquet"], [], limit: 100);
            self::fail('expected ScanIoException');
        } catch (ScanIoException $e) {
            self::assertStringNotContainsString($this->tempStorageRoot(), $e->getMessage(), 'absolute path must NOT leak in scanner errors');
            self::assertStringNotContainsString($dir, $e->getMessage());
            self::assertStringContainsString('part-CORRUPT.parquet', $e->getMessage(), 'basename is fine; it carries no sensitive info');
        }
    }

    public function testNonExistentPartitionGlobReturnsEmpty(): void
    {
        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$this->tempStorageRoot().'/logs/no-such-tenant/date=2026-05-09/hour=14/part-*.parquet'], [], limit: 100);

        self::assertSame([], $result->rows);
        self::assertNull($result->position);
        self::assertFalse($result->hasMore);
    }

    public function testResumeFromSkipsPreviousPage(): void
    {
        $rows = [];
        for ($i = 0; $i < 10; ++$i) {
            $rows[] = ['service' => 'checkout', 'severity' => 9 + $i, 'body' => "line-$i", 'time_unix_nano' => 1_778_421_600_000_000_000 + $i * 1000];
        }
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', $rows);

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);

        // First page
        $page1 = $scanner->scan([$glob], [], limit: 4);
        self::assertCount(4, $page1->rows);
        self::assertNotNull($page1->position);

        // Second page resumes from page 1's position
        $page2 = $scanner->scan([$glob], [], limit: 4, resumeFrom: $page1->position);
        self::assertCount(4, $page2->rows);

        // No overlap between pages
        $page1Bodies = array_column($page1->rows, 'body_json');
        $page2Bodies = array_column($page2->rows, 'body_json');
        self::assertSame([], array_intersect($page1Bodies, $page2Bodies));
    }

    public function testRoundTripWriteAndRead(): void
    {
        // Same library on both sides (flow-php Reader/Writer).
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', [
            ['service' => 'checkout', 'severity' => 17, 'body' => 'hello-rt'],
        ]);

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [], limit: 100);

        self::assertCount(1, $result->rows);
        $row = $result->rows[0];
        self::assertSame('checkout', $row['resource_service_name']);
        self::assertSame(17, $row['severity_number']);
        self::assertSame('logs/v1', $row['_schema_id']);
    }

    public function testScanTimeoutSurfacesAsScanTimeoutException(): void
    {
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'a'],
            ['service' => 'checkout', 'severity' => 9, 'body' => 'b'],
        ]);

        // executionTimeoutSeconds=0 → deadline equals current timestamp;
        // first row tripping the check raises ScanTimeoutException.
        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 0);

        $this->expectException(ScanTimeoutException::class);
        $scanner->scan([$glob], [], limit: 100);
    }

    public function testRowGroupPushDownSkipsFilesViaMinMaxStatistics(): void
    {
        // Three files in the same partition. Each file holds one row group
        // (default writer config). Severities chosen so the row-group
        // statistics make the skip-or-scan decision unambiguous:
        //   file A: severity_number ∈ {9}        → max=9
        //   file B: severity_number ∈ {17, 20}   → min=17, max=20
        //   file C: severity_number ∈ {15}       → max=15
        // With severityNumberMin=17, A and C are refuted by metadata; B is kept.
        $tenant = 'acme';
        $this->writeLogsFixtureForFile($tenant, '2026-05-09', '14', 'AAA', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'a1'],
        ]);
        $this->writeLogsFixtureForFile($tenant, '2026-05-09', '14', 'BBB', [
            ['service' => 'checkout', 'severity' => 17, 'body' => 'b1'],
            ['service' => 'checkout', 'severity' => 20, 'body' => 'b2'],
        ]);
        $this->writeLogsFixtureForFile($tenant, '2026-05-09', '14', 'CCC', [
            ['service' => 'checkout', 'severity' => 15, 'body' => 'c1'],
        ]);

        $glob = $this->tempStorageRoot()."/logs/$tenant/date=2026-05-09/hour=14/part-*.parquet";

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [new ColumnGreaterEqual('severity_number', 17)], limit: 100);

        // Only file B's two rows should be returned.
        self::assertCount(2, $result->rows);
        $bodies = array_column($result->rows, 'body_json');
        self::assertContains(json_encode(['stringValue' => 'b1']), $bodies);
        self::assertContains(json_encode(['stringValue' => 'b2']), $bodies);

        // Counters: 2 row groups skipped (A and C), 1 scanned (B).
        self::assertSame(1, $result->groupsScanned, 'one row group should be scanned');
        self::assertSame(2, $result->groupsSkipped, 'two row groups should be elided by min/max push-down');
    }

    public function testRowGroupPushDownSkipsByTimeWindowRange(): void
    {
        // Three files in the same partition, each holding rows whose
        // time_unix_nano falls entirely-before / inside / entirely-after a
        // requested [since, until] window. The time-window push-down
        // (a `ColumnInRange` predicate on `time_unix_nano`) should refute
        // the boundary files and keep only the inside-window file.
        $tenant = 'acme';
        $beforeNano = (int) (new \DateTimeImmutable('2026-05-09 13:00:00 UTC'))->format('U') * 1_000_000_000;
        $insideNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;
        $afterNano = (int) (new \DateTimeImmutable('2026-05-09 16:00:00 UTC'))->format('U') * 1_000_000_000;
        $sinceNano = (int) (new \DateTimeImmutable('2026-05-09 14:00:00 UTC'))->format('U') * 1_000_000_000;
        $untilNano = (int) (new \DateTimeImmutable('2026-05-09 15:00:00 UTC'))->format('U') * 1_000_000_000;

        $this->writeLogsFixtureForFile($tenant, '2026-05-09', '14', 'AAA', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'before-window', 'time_unix_nano' => $beforeNano],
        ]);
        $this->writeLogsFixtureForFile($tenant, '2026-05-09', '14', 'BBB', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'inside-window', 'time_unix_nano' => $insideNano],
        ]);
        $this->writeLogsFixtureForFile($tenant, '2026-05-09', '14', 'CCC', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'after-window', 'time_unix_nano' => $afterNano],
        ]);

        $glob = $this->tempStorageRoot()."/logs/$tenant/date=2026-05-09/hour=14/part-*.parquet";

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan(
            [$glob],
            [new \App\Read\Compute\Predicates\ColumnInRange('time_unix_nano', $sinceNano, $untilNano)],
            limit: 100,
        );

        self::assertCount(1, $result->rows, 'only the inside-window file should produce rows');
        self::assertSame(json_encode(['stringValue' => 'inside-window']), $result->rows[0]['body_json']);
        self::assertSame(1, $result->groupsScanned);
        self::assertSame(2, $result->groupsSkipped, 'before- and after-window row groups must be elided by metadata push-down');
    }

    public function testRowGroupPushDownLeavesStringPredicatesAlone(): void
    {
        // No numeric predicate in this query → row groups cannot be skipped
        // by metadata; every group's data is opened.
        $this->writeLogsFixtureForFile('acme', '2026-05-09', '14', 'AAA', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'a'],
        ]);
        $this->writeLogsFixtureForFile('acme', '2026-05-09', '14', 'BBB', [
            ['service' => 'payments', 'severity' => 9, 'body' => 'b'],
        ]);

        $glob = $this->tempStorageRoot().'/logs/acme/date=2026-05-09/hour=14/part-*.parquet';

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [new ColumnEquals('resource_service_name', 'checkout')], limit: 100);

        self::assertCount(1, $result->rows);
        self::assertSame(2, $result->groupsScanned, 'string-only predicates do not refute row groups');
        self::assertSame(0, $result->groupsSkipped);
    }

    public function testGreaterEqualPredicate(): void
    {
        $glob = $this->writeLogsFixture('acme', '2026-05-09', '14', [
            ['service' => 'checkout', 'severity' => 9, 'body' => 'info'],
            ['service' => 'checkout', 'severity' => 17, 'body' => 'error'],
            ['service' => 'checkout', 'severity' => 21, 'body' => 'fatal'],
        ]);

        $scanner = new ParquetScanner(new MockClock('2026-05-09 14:30:00 UTC'), executionTimeoutSeconds: 10);
        $result = $scanner->scan([$glob], [new ColumnGreaterEqual('severity_number', 17)], limit: 100);

        self::assertCount(2, $result->rows);
        self::assertGreaterThanOrEqual(17, $result->rows[0]['severity_number']);
        self::assertGreaterThanOrEqual(17, $result->rows[1]['severity_number']);
    }

    /**
     * Writes a single Parquet file into the partition with a stable ULID
     * prefix so multi-file tests can sort/identify the file.
     *
     * @param list<array{service: string, severity: int, body: string, time_unix_nano?: int}> $records
     */
    private function writeLogsFixtureForFile(string $tenant, string $date, string $hour, string $ulidPrefix, array $records): string
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $writer = new ParquetFileWriter($logsSchema, Compressions::GZIP);
        $extractor = new AttributeColumnExtractor($logsSchema);

        $clock = new MockClock("$date $hour:30:00 UTC");
        $resolver = new PartitionPathResolver(
            $clock,
            new StubFilenameGenerator($ulidPrefix.str_repeat('0', max(0, 26 - \strlen($ulidPrefix)))),
            $this->tempStorageRoot(),
        );

        $service = new LogsIngestService($writer, $resolver, $extractor);
        $service->write($this->buildLogsRequest($records), new Tenant($tenant, $tenant));

        return $this->tempStorageRoot()."/logs/$tenant/date=$date/hour=$hour/part-*.parquet";
    }

    /**
     * Builds an OTLP Logs request from a list of (service, severity, body)
     * tuples and writes it via the actual LogsIngestService into a partition
     * under the test's temp storage root. Returns the partition's file glob.
     *
     * @param list<array{service: string, severity: int, body: string, time_unix_nano?: int}> $records
     */
    private function writeLogsFixture(string $tenant, string $date, string $hour, array $records): string
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $writer = new ParquetFileWriter($logsSchema, Compressions::GZIP);
        $extractor = new AttributeColumnExtractor($logsSchema);

        $clock = new MockClock("$date $hour:30:00 UTC");
        $resolver = new PartitionPathResolver(
            $clock,
            new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))),
            $this->tempStorageRoot(),
        );

        $service = new LogsIngestService($writer, $resolver, $extractor);
        $service->write($this->buildLogsRequest($records), new Tenant($tenant, $tenant));

        return $this->tempStorageRoot()."/logs/$tenant/date=$date/hour=$hour/part-*.parquet";
    }

    /**
     * @param list<array{service: string, severity: int, body: string, time_unix_nano?: int}> $records
     */
    private function buildLogsRequest(array $records): ExportLogsServiceRequestDto
    {
        // Group records by service so each service gets its own ResourceLogs.
        $grouped = [];
        foreach ($records as $r) {
            $grouped[$r['service']][] = $r;
        }

        $resourceLogs = [];
        foreach ($grouped as $service => $serviceRecords) {
            $logRecords = [];
            foreach ($serviceRecords as $r) {
                $logRecords[] = new LogRecordDto(
                    timeUnixNano: $r['time_unix_nano'] ?? 1_778_421_600_000_000_000,
                    observedTimeUnixNano: null,
                    severityNumber: $r['severity'],
                    severityText: 17 === $r['severity'] ? 'ERROR' : 'INFO',
                    body: AnyValueDto::string($r['body']),
                    attributes: [],
                    droppedAttributesCount: 0,
                    traceId: null,
                    spanId: null,
                    flags: null,
                );
            }
            $resourceLogs[] = new ResourceLogsDto(
                resourceAttributes: [
                    new KeyValueDto('service.name', AnyValueDto::string($service)),
                ],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: $logRecords,
                )],
            );
        }

        return new ExportLogsServiceRequestDto($resourceLogs);
    }
}
