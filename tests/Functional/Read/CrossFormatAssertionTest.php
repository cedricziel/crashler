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
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use Flow\Parquet\ParquetFile\Compressions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Cross-format equivalence: same query through different `Accept`
 * headers returns the same data in different shapes.
 *
 * Format containers per AP4 (after our PerRowLinksListener +
 * NextCursorInjector run):
 *
 *   Hydra (jsonld) → top-level @context, @id, @var=Collection, member[]
 *   HAL            → top-level _embedded.<resource>[], _links
 *   compact JSON   → top-level array (no envelope) OR {rows, _links}
 *                    when NextCursorInjector wraps
 *   JSON:API       → top-level data[]
 */
final class CrossFormatAssertionTest extends KernelTestCase
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

    public function testHydraReturnsMemberArray(): void
    {
        $this->writeOneLog();
        $body = $this->fetchAs('application/ld+json');

        self::assertArrayHasKey('member', $body);
        self::assertCount(1, $body['member']);
        self::assertSame('boom', json_decode($body['member'][0]['bodyJson'], true)['stringValue']);
    }

    public function testHalReturnsEmbeddedArray(): void
    {
        $this->writeOneLog();
        $body = $this->fetchAs('application/hal+json');

        self::assertArrayHasKey('_embedded', $body);
        $rows = $this->extractEmbeddedRows($body);
        self::assertCount(1, $rows);
        self::assertSame('boom', json_decode($rows[0]['bodyJson'], true)['stringValue']);
    }

    public function testCompactJsonReturnsArray(): void
    {
        $this->writeOneLog();
        $body = $this->fetchAs('application/json');

        // AP's plain JSON output is just the array of items; our
        // NextCursorInjector wraps in {rows, _links} when there's a
        // next link. Without one, the array is top-level.
        self::assertTrue(array_is_list($body) || isset($body['rows']));
        $rows = array_is_list($body) ? $body : $body['rows'];
        self::assertCount(1, $rows);
    }

    public function testJsonApiReturnsDataArray(): void
    {
        $this->writeOneLog();
        $body = $this->fetchAs('application/vnd.api+json');

        self::assertArrayHasKey('data', $body);
        self::assertCount(1, $body['data']);
        // JSON:API wraps the resource in {id, type, attributes}
        self::assertArrayHasKey('attributes', $body['data'][0]);
    }

    public function testInt64ColumnsSerializeAsJsonStrings(): void
    {
        // §5.10: Parquet INT64 columns (time_unix_nano) must serialize
        // as JSON strings in the response, not numbers, so int64
        // precision is preserved on consumers (mirrors the OTLP/HTTP-JSON
        // convention). The Resource DTOs declare `string` types for
        // these fields; this test verifies the wire output.
        $this->writeOneLog();
        $body = $this->fetchAs('application/ld+json');
        $row = $body['member'][0];

        // timeUnixNano must be a JSON string, not a number.
        self::assertIsString($row['timeUnixNano'], 'timeUnixNano must be a JSON string for int64 precision preservation');
        self::assertMatchesRegularExpression('/^\d+$/', $row['timeUnixNano']);
    }

    public function testEquivalentRowDataAcrossFormats(): void
    {
        $this->writeOneLog();
        $hydra = $this->fetchAs('application/ld+json')['member'][0];
        $compact = $this->fetchAs('application/json');
        $compactRow = (array_is_list($compact) ? $compact : $compact['rows'])[0];
        $jsonApi = $this->fetchAs('application/vnd.api+json')['data'][0]['attributes'];
        $halBody = $this->fetchAs('application/hal+json');
        $halRow = $this->extractEmbeddedRows($halBody)[0];

        // Property-by-property equivalence on the load-bearing fields.
        // Each format may add its own metadata (Hydra adds @id/@type;
        // JSON:API wraps in attributes; HAL adds _links), but the
        // documented columns are the same.
        foreach (['timeUnixNano', 'severityNumber', 'bodyJson', 'resourceServiceName'] as $key) {
            self::assertSame($hydra[$key], $compactRow[$key], "compact differs from hydra on `$key`");
            self::assertSame($hydra[$key], $jsonApi[$key], "jsonapi differs from hydra on `$key`");
            self::assertSame($hydra[$key], $halRow[$key], "hal differs from hydra on `$key`");
        }
    }

    public function testUnsupportedAcceptHeaderRejected(): void
    {
        $browser = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&limit=1', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Accept' => 'text/plain',
                ],
            ]);

        // AP returns 406 for unsupported Accept (Symfony's content
        // negotiation); the spec previously said 415. Both are valid
        // rejection responses; we accept AP's convention.
        $status = $browser->client()->getResponse()->getStatusCode();
        self::assertContains($status, [406, 415], "expected 406 or 415, got {$status}");
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAs(string $accept): array
    {
        $browser = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&limit=10', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Accept' => $accept,
                ],
            ])
            ->assertStatus(200);

        return json_decode((string) $browser->client()->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractEmbeddedRows(array $body): array
    {
        foreach ($body['_embedded'] ?? [] as $items) {
            if (\is_array($items)) {
                return $items;
            }
        }

        return [];
    }

    private function writeOneLog(): void
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
                        traceId: null,
                        spanId: null,
                        flags: null,
                    )],
                )],
            ),
        ]), new Tenant('test-tenant', 'test-tenant'));
    }
}
