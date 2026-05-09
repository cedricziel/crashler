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
use App\Read\State\LogsStateProvider;
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
 * GET-side multi-attribute filter composition.
 *
 * Two distinct `attribute.<key>=<value>` parameters compose with logical
 * AND. Rows that match only one of the two are excluded.
 */
#[CoversClass(LogsStateProvider::class)]
final class MultiAttributeFiltersTest extends KernelTestCase
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

    public function testTwoAttributeFiltersComposeWithAnd(): void
    {
        // Three rows, one per combination:
        //   row A: exception.type=Boom, http.method=POST     → matches both
        //   row B: exception.type=Boom, http.method=GET      → matches only the first
        //   row C: exception.type=Other, http.method=POST    → matches only the second
        $this->writeLog('test-tenant', 'a', exceptionType: 'Boom', httpMethod: 'POST');
        $this->writeLog('test-tenant', 'b', exceptionType: 'Boom', httpMethod: 'GET');
        $this->writeLog('test-tenant', 'c', exceptionType: 'Other', httpMethod: 'POST');

        $browser = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&attribute.exception.type=Boom&attribute.http.method=POST', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $body = json_decode((string) $browser->client()->getResponse()->getContent(), true);
        $rows = $body['member'];

        self::assertCount(1, $rows);
        self::assertSame(json_encode(['stringValue' => 'a']), $rows[0]['bodyJson']);
    }

    private function writeLog(string $tenant, string $bodyText, string $exceptionType, string $httpMethod): void
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
                        timeUnixNano: $clockUnixNano + random_int(0, 999_999),
                        observedTimeUnixNano: null,
                        severityNumber: 17,
                        severityText: 'ERROR',
                        body: AnyValueDto::string($bodyText),
                        attributes: [
                            new KeyValueDto('exception.type', AnyValueDto::string($exceptionType)),
                            new KeyValueDto('http.method', AnyValueDto::string($httpMethod)),
                        ],
                        droppedAttributesCount: 0,
                        traceId: null,
                        spanId: null,
                        flags: null,
                    )],
                )],
            ),
        ]), new Tenant($tenant, $tenant));
    }
}
