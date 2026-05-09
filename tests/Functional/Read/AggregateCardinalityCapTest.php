<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Logs\LogsIngestService;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Read\Compute\AggregatingScanner;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use Flow\Parquet\ParquetFile\Compressions;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Aggregation cardinality cap end-to-end: write 201 distinct services into
 * a single partition under one tenant, then aggregate count by service.
 * The default cap is 200 distinct groups; the response must be 400 with a
 * message naming the cap.
 */
#[CoversClass(AggregatingScanner::class)]
final class AggregateCardinalityCapTest extends KernelTestCase
{
    use HasBrowser;
    use TempStorageRoot;

    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testGroupByServiceOverCardinalityCapReturns400(): void
    {
        $services = [];
        for ($i = 0; $i < 201; ++$i) {
            $services[] = \sprintf('svc-%03d', $i);
        }
        $this->writeOneLogPerService('test-tenant', $services);

        $browser = $this->browser()
            ->get('/v1/logs/aggregate?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&function=count&groupBy=service', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);

        $body = json_decode((string) $browser->client()->getResponse()->getContent(), true);
        self::assertStringContainsString('200', $body['message'], 'error message must name the cap (200)');
    }

    /**
     * @param list<string> $services
     */
    private function writeOneLogPerService(string $tenant, array $services): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;

        $resourceLogs = [];
        foreach ($services as $i => $service) {
            $resourceLogs[] = new ResourceLogsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string($service))],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: [new LogRecordDto(
                        timeUnixNano: $clockUnixNano + $i * 1_000,
                        observedTimeUnixNano: null,
                        severityNumber: 9,
                        severityText: 'INFO',
                        body: AnyValueDto::string('row-'.$i),
                        attributes: [],
                        droppedAttributesCount: 0,
                        traceId: null,
                        spanId: null,
                        flags: null,
                    )],
                )],
            );
        }

        $svc = new LogsIngestService(
            new ParquetFileWriter($logsSchema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
            new AttributeColumnExtractor($logsSchema),
        );
        $svc->write(new ExportLogsServiceRequestDto($resourceLogs), new Tenant($tenant, $tenant));
    }
}
