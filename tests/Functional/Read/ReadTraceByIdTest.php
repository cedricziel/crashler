<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\ResourceSpansDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Read\Controller\ReadTraceController;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use App\Traces\TracesIngestService;
use Flow\Parquet\ParquetFile\Compressions;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(ReadTraceController::class)]
final class ReadTraceByIdTest extends KernelTestCase
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

    public function testTraceByIdRequiresBearer(): void
    {
        $this->browser()
            ->get('/v1/traces/5b8aa5a2d2c872e8321cf37308d69df2')
            ->assertStatus(401);
    }

    public function testTraceByIdMalformedHexRejected(): void
    {
        // Route requirement: /[0-9a-f]{32}/ — anything else 404s at the
        // routing layer.
        $this->browser()
            ->get('/v1/traces/zzzz')
            ->assertStatus(404);
    }

    public function testTraceByIdNotFoundReturns404(): void
    {
        $response = $this->browser()
            ->get('/v1/traces/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(404);

        $message = $response->json()->decoded()['message'];
        self::assertStringContainsString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $message);
        self::assertStringContainsString('not found', $message);
        self::assertStringContainsString('since/until', $message);
    }

    public function testTraceByIdReturnsResourceSpansShape(): void
    {
        $traceHex = '5b8aa5a2d2c872e8321cf37308d69df2';
        $this->writeOneSpanFixture('test-tenant', $traceHex, '2026-05-09', '14');

        $this->browser()
            ->get(\sprintf('/v1/traces/%s?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z', $traceHex), [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200)
            ->assertJson()
            ->assertJsonMatches('_links.self', "/v1/traces/{$traceHex}")
            ->assertJsonMatches('resourceSpans[0].scopeSpans[0].spans[0].traceId', $traceHex);
    }

    public function testTraceByIdLinksToLogsAndMetrics(): void
    {
        $traceHex = '5b8aa5a2d2c872e8321cf37308d69df2';
        $this->writeOneSpanFixture('test-tenant', $traceHex, '2026-05-09', '14');

        $response = $this->browser()
            ->get(\sprintf('/v1/traces/%s?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z', $traceHex), [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $body = $response->json()->decoded();
        self::assertStringStartsWith("/v1/logs?traceId={$traceHex}&since=", $body['_links']['logs']);
        self::assertStringStartsWith("/v1/metrics?exemplarTraceId={$traceHex}&since=", $body['_links']['metricsWithExemplars']);
    }

    private function writeOneSpanFixture(string $tenant, string $traceHex, string $date, string $hour): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $tracesSchema = $catalog->latestFor('traces');
        $writer = new ParquetFileWriter($tracesSchema, Compressions::GZIP);
        $extractor = new AttributeColumnExtractor($tracesSchema);

        $clock = new MockClock("$date $hour:30:00 UTC");
        $resolver = new PartitionPathResolver(
            $clock,
            new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))),
            $this->tempStorageRoot(),
        );

        $service = new TracesIngestService($writer, $resolver, $extractor);

        $traceBytes = (string) hex2bin($traceHex);
        $spanBytes = (string) hex2bin('051581bf3cb55c13');

        $request = new ExportTraceServiceRequestDto([
            new ResourceSpansDto(
                resourceAttributes: [
                    new KeyValueDto('service.name', AnyValueDto::string('checkout')),
                ],
                scopeSpans: [new ScopeSpansDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    spans: [new SpanDto(
                        traceId: $traceBytes,
                        spanId: $spanBytes,
                        parentSpanId: null,
                        traceState: null,
                        flags: null,
                        name: 'GET /orders/:id',
                        kind: 2,
                        startTimeUnixNano: 1714752000000000000,
                        endTimeUnixNano: 1714752000050000000,
                        attributes: [],
                        events: [],
                        links: [],
                        status: null,
                        droppedAttributesCount: 0,
                        droppedEventsCount: 0,
                        droppedLinksCount: 0,
                    )],
                )],
            ),
        ]);

        $service->write($request, new Tenant($tenant, $tenant));
    }
}
