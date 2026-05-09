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
use App\Read\Controller\AggregateLogsController;
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

#[CoversClass(AggregateLogsController::class)]
final class AggregateLogsTest extends KernelTestCase
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

    public function testCountWithoutGroupByReturnsOneRow(): void
    {
        $this->writeLogs('test-tenant', ['a', 'b', 'c'], 'checkout');

        $body = $this->fetch('/v1/logs/aggregate?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&function=count');

        self::assertSame('count', $body['function']);
        self::assertCount(1, $body['rows']);
        self::assertSame(3, $body['rows'][0]['value']);
    }

    public function testCountGroupByService(): void
    {
        $this->writeLogs('test-tenant', ['a', 'b'], 'checkout');
        $this->writeLogs('test-tenant', ['c'], 'payments');

        $body = $this->fetch('/v1/logs/aggregate?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&function=count&groupBy=service');

        self::assertCount(2, $body['rows']);
        $byService = [];
        foreach ($body['rows'] as $row) {
            $byService[$row['group']['resource_service_name']] = $row['value'];
        }
        self::assertSame(2, $byService['checkout']);
        self::assertSame(1, $byService['payments']);
    }

    public function testSumOnSeverityNumber(): void
    {
        $this->writeLogs('test-tenant', ['a', 'b', 'c'], 'checkout', severity: 17);

        $body = $this->fetch('/v1/logs/aggregate?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&function=sum&column=severityNumber');

        self::assertSame('sum', $body['function']);
        self::assertSame(17 * 3, $body['rows'][0]['value']);
        self::assertSame(3, $body['rows'][0]['sample_count']);
    }

    public function testFunctionMissingReturns400(): void
    {
        $this->browser()
            ->get('/v1/logs/aggregate?since=1h', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testColumnRequiredForNonCount(): void
    {
        $this->browser()
            ->get('/v1/logs/aggregate?since=1h&function=sum', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testUnsupportedFunctionRejected(): void
    {
        $this->browser()
            ->get('/v1/logs/aggregate?since=1h&function=p99&column=severityNumber', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    public function testIntervalNotImplemented(): void
    {
        $this->browser()
            ->get('/v1/logs/aggregate?since=1h&function=count&interval=1h', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(501);
    }

    public function testGroupByOnJsonColumnRejected(): void
    {
        $this->browser()
            ->get('/v1/logs/aggregate?since=1h&function=count&groupBy=attributesJson', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $url): array
    {
        $browser = $this->browser()
            ->get($url, [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        return json_decode((string) $browser->client()->getResponse()->getContent(), true);
    }

    /**
     * @param list<string> $bodies
     */
    private function writeLogs(string $tenant, array $bodies, string $service, int $severity = 9): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;

        $svc = new LogsIngestService(
            new ParquetFileWriter($logsSchema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
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
        ]), new Tenant($tenant, $tenant));
    }
}
