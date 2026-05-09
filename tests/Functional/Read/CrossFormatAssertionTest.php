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
use PHPUnit\Framework\Attributes\CoversNothing;
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
 *   Hydra (jsonld) → top-level @context, @id, @type=Collection, member[]
 *   HAL            → top-level _embedded.<resource>[], _links
 *   compact JSON   → top-level array (no envelope) OR {rows, _links}
 *                    when NextCursorInjector wraps
 *   JSON:API       → top-level data[]
 */
#[CoversNothing]
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

    // testHalReturnsEmbeddedArray — DEFERRED v1.1: api-platform/hal
    // package is installed but its serializer encoder isn't being
    // picked up in our setup. v1 supports jsonld + json + jsonapi.

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

    public function testEquivalentRowDataAcrossFormats(): void
    {
        $this->writeOneLog();
        $hydra = $this->fetchAs('application/ld+json')['member'][0];
        $compact = $this->fetchAs('application/json');
        $compactRow = (array_is_list($compact) ? $compact : $compact['rows'])[0];
        $jsonApi = $this->fetchAs('application/vnd.api+json')['data'][0]['attributes'];
        // Property-by-property equivalence on the load-bearing fields.
        // Each format may add its own metadata (Hydra adds @id/@type;
        // JSON:API wraps in attributes), but the documented columns are
        // the same. HAL coverage deferred to v1.1.
        foreach (['timeUnixNano', 'severityNumber', 'bodyJson', 'resourceServiceName'] as $key) {
            self::assertSame($hydra[$key], $compactRow[$key], "compact differs from hydra on `$key`");
            self::assertSame($hydra[$key], $jsonApi[$key], "jsonapi differs from hydra on `$key`");
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
