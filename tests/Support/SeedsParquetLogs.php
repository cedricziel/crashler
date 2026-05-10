<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Logs\LogsIngestService;
use App\Otlp\AttributeColumnExtractor;
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
use Flow\Parquet\ParquetFile\Compressions;
use Symfony\Component\Clock\MockClock;

/**
 * Seeds parquet log rows for a tenant, into the temp storage root provided
 * by {@see TempStorageRoot}. Used by Live Component tests so they exercise
 * the full read path (scanner + accumulators + Twig render) instead of
 * mocking out the data services.
 *
 * Tests using this trait MUST also use {@see TempStorageRoot} and set the
 * APP_SHARE_DIR env var before booting the kernel.
 */
trait SeedsParquetLogs
{
    /**
     * @param list<string> $bodies
     *
     * @return array{since_ns: int, until_ns: int} the resolved nanosecond
     *                                             time window the seeded
     *                                             rows fall inside
     */
    protected function seedLogs(string $tenantSlug, array $bodies, string $service = 'checkout', int $severity = 9, string $atIso = '2026-05-09 14:30:00 UTC'): array
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 2).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $clock = new MockClock($atIso);
        $clockUnixNano = (int) (new \DateTimeImmutable($atIso))->format('U') * 1_000_000_000;

        $svc = new LogsIngestService(
            new ParquetFileWriter($logsSchema, Compressions::GZIP),
            new PartitionPathResolver(
                $clock,
                new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))),
                $this->tempStorageRoot(),
            ),
            new AttributeColumnExtractor($logsSchema),
        );

        $records = [];
        foreach ($bodies as $i => $body) {
            $records[] = new LogRecordDto(
                timeUnixNano: $clockUnixNano + $i * 1_000_000,
                observedTimeUnixNano: null,
                severityNumber: $severity,
                severityText: 'INFO',
                body: AnyValueDto::string($body),
                attributes: [],
                droppedAttributesCount: 0,
                traceId: null,
                spanId: null,
                flags: null,
            );
        }

        $svc->write(new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string($service))],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: $records,
                )],
            ),
        ]), new Tenant($tenantSlug, $tenantSlug));

        // Window that comfortably contains the seeded rows.
        return [
            'since_ns' => $clockUnixNano - 60 * 1_000_000_000,
            'until_ns' => $clockUnixNano + 60 * 1_000_000_000,
        ];
    }
}
