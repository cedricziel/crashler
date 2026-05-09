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
use App\Read\State\TracesStateProvider;
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

#[CoversClass(TracesStateProvider::class)]
final class MultiAttributeTracesFiltersTest extends KernelTestCase
{
    use HasBrowser;
    use TempStorageRoot;

    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';
    private const string TRACE_HEX = '5b8aa5a2d2c872e8321cf37308d69df2';

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testTwoAttributeFiltersComposeWithAndOnTraces(): void
    {
        // Span A — both attributes match: returned.
        $this->writeSpan('a', method: 'POST', route: '/checkout');
        // Span B — only http.method matches: excluded.
        $this->writeSpan('b', method: 'POST', route: '/products');
        // Span C — only http.route matches: excluded.
        $this->writeSpan('c', method: 'GET', route: '/checkout');

        $browser = $this->browser()
            ->get('/v1/traces?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&attribute.http.method=POST&attribute.http.route=/checkout', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $body = json_decode((string) $browser->client()->getResponse()->getContent(), true);
        $rows = $body['member'];

        self::assertCount(1, $rows, 'only the doubly-matching span must be returned');
        self::assertSame('a', $rows[0]['name']);
    }

    private function writeSpan(string $name, string $method, string $route): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $schema = $catalog->latestFor('traces');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;

        $service = new TracesIngestService(
            new ParquetFileWriter($schema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
            new AttributeColumnExtractor($schema),
        );
        $service->write(new ExportTraceServiceRequestDto([
            new ResourceSpansDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeSpans: [new ScopeSpansDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    spans: [new SpanDto(
                        traceId: (string) hex2bin(self::TRACE_HEX),
                        spanId: random_bytes(8),
                        parentSpanId: null,
                        traceState: null,
                        flags: null,
                        name: $name,
                        kind: 2,
                        startTimeUnixNano: $clockUnixNano + random_int(0, 999_999),
                        endTimeUnixNano: $clockUnixNano + 5_000_000,
                        attributes: [
                            new KeyValueDto('http.method', AnyValueDto::string($method)),
                            new KeyValueDto('http.route', AnyValueDto::string($route)),
                        ],
                        events: [],
                        links: [],
                        status: null,
                        droppedAttributesCount: 0,
                        droppedEventsCount: 0,
                        droppedLinksCount: 0,
                    )],
                )],
            ),
        ]), new Tenant('test-tenant', 'test-tenant'));
    }
}
