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
use App\Read\Http\PerRowLinksListener;
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

#[CoversClass(PerRowLinksListener::class)]
final class PerRowLinksTest extends KernelTestCase
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

    public function testLogRowWithTraceContextCarriesPerRowLinks(): void
    {
        $this->writeOneLog('test-tenant', traceId: (string) hex2bin(self::TRACE_HEX), spanId: (string) hex2bin(self::SPAN_HEX));

        $response = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&limit=10', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $body = json_decode((string) $response->client()->getResponse()->getContent(), true);
        $rows = $body['member'] ?? [];
        self::assertNotEmpty($rows);

        $first = $rows[0];
        self::assertArrayHasKey('_links', $first);
        self::assertSame('/v1/traces/'.self::TRACE_HEX, $first['_links']['trace']);
        self::assertSame('/v1/spans/'.self::SPAN_HEX, $first['_links']['span']);
    }

    public function testLogRowWithoutTraceContextOmitsLinks(): void
    {
        $this->writeOneLog('test-tenant', traceId: null, spanId: null);

        $response = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&limit=10', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $body = json_decode((string) $response->client()->getResponse()->getContent(), true);
        $rows = $body['member'] ?? [];
        self::assertNotEmpty($rows);

        $first = $rows[0];
        self::assertArrayNotHasKey('_links', $first, 'rows without trace context must not carry _links');
    }

    private function writeOneLog(string $tenant, ?string $traceId, ?string $spanId): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsSchema = $catalog->latestFor('logs');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;

        $service = new LogsIngestService(
            new ParquetFileWriter($logsSchema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
            new AttributeColumnExtractor($logsSchema),
        );
        $service->write(new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: [new LogRecordDto(
                        timeUnixNano: $clockUnixNano + 10_000_000,
                        observedTimeUnixNano: null,
                        severityNumber: 17,
                        severityText: 'ERROR',
                        body: AnyValueDto::string('boom'),
                        attributes: [],
                        droppedAttributesCount: 0,
                        traceId: $traceId,
                        spanId: $spanId,
                        flags: null,
                    )],
                )],
            ),
        ]), new Tenant($tenant, $tenant));
    }
}
