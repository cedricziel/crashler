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
use App\Read\Http\NextCursorInjector;
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
 * End-to-end cursor pagination via Hydra `view.next` injection.
 *
 * The state provider stashes the next cursor URL on the request when
 * the scanner reports `hasMore`; NextCursorInjector picks it up at
 * response time and patches the appropriate format-specific affordance.
 */
#[CoversClass(NextCursorInjector::class)]
final class CursorPaginationTest extends KernelTestCase
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

    public function testThreePageChainViaNextLink(): void
    {
        $bodies = [];
        for ($i = 0; $i < 25; ++$i) {
            $bodies[] = \sprintf('msg-%02d', $i);
        }
        $this->writeLogs('test-tenant', $bodies);

        // Page 1
        $page1 = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&limit=10', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
        $body1 = json_decode((string) $page1->client()->getResponse()->getContent(), true);
        $rows1 = $body1['member'] ?? [];
        self::assertCount(10, $rows1);

        $nextUrl = $body1['view']['next'] ?? null;
        self::assertNotNull($nextUrl, 'first page must carry a view.next link');

        // Page 2
        $page2 = $this->browser()
            ->get($nextUrl, [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
        $body2 = json_decode((string) $page2->client()->getResponse()->getContent(), true);
        $rows2 = $body2['member'] ?? [];
        self::assertCount(10, $rows2);
        $nextUrl2 = $body2['view']['next'] ?? null;
        self::assertNotNull($nextUrl2, 'page 2 must carry a view.next link');

        // Page 3 (last — only 5 rows remaining)
        $page3 = $this->browser()
            ->get($nextUrl2, [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
        $body3 = json_decode((string) $page3->client()->getResponse()->getContent(), true);
        $rows3 = $body3['member'] ?? [];
        self::assertCount(5, $rows3);
        $nextUrl3 = $body3['view']['next'] ?? null;
        self::assertNull($nextUrl3, 'last page must not carry a view.next link');

        // No overlap between pages
        $bodiesPage1 = array_column($rows1, 'bodyJson');
        $bodiesPage2 = array_column($rows2, 'bodyJson');
        $bodiesPage3 = array_column($rows3, 'bodyJson');

        self::assertSame([], array_intersect($bodiesPage1, $bodiesPage2));
        self::assertSame([], array_intersect($bodiesPage2, $bodiesPage3));
        self::assertSame([], array_intersect($bodiesPage1, $bodiesPage3));

        // Combined coverage: all 25 records exactly once
        $allBodies = array_unique(array_merge($bodiesPage1, $bodiesPage2, $bodiesPage3));
        self::assertCount(25, $allBodies);
    }

    public function testTamperedCursorRejected(): void
    {
        $this->writeLogs('test-tenant', ['a', 'b', 'c']);
        $page1 = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&limit=2', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
        $body = json_decode((string) $page1->client()->getResponse()->getContent(), true);
        $nextUrl = $body['view']['next'] ?? null;
        self::assertNotNull($nextUrl);

        // Flip a character mid-cursor (URL has format /v1/logs?cursor=<base64>.<sig>)
        // Tamper the signature segment.
        $tampered = preg_replace('/(.{3})$/', 'XXX', $nextUrl);

        $this->browser()
            ->get($tampered, [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(400);
    }

    /**
     * @param list<string> $bodies
     */
    private function writeLogs(string $tenant, array $bodies): void
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

        $records = [];
        foreach ($bodies as $i => $body) {
            $records[] = new LogRecordDto(
                timeUnixNano: $clockUnixNano + $i * 1_000_000,
                observedTimeUnixNano: null,
                severityNumber: 9,
                severityText: 'INFO',
                body: AnyValueDto::string($body),
                attributes: [],
                droppedAttributesCount: 0,
                traceId: null,
                spanId: null,
                flags: null,
            );
        }

        $service->write(new ExportLogsServiceRequestDto([
            new ResourceLogsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeLogs: [new ScopeLogsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    logRecords: $records,
                )],
            ),
        ]), new Tenant($tenant, $tenant));
    }
}
