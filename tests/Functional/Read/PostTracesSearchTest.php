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
use App\Otlp\Dto\SpanStatusDto;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Tests\Support\StubFilenameGenerator;
use App\Tests\Support\TempStorageRoot;
use App\Traces\TracesIngestService;
use Flow\Parquet\ParquetFile\Compressions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Browser\Test\HasBrowser;

final class PostTracesSearchTest extends KernelTestCase
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

    public function testKindOrComposition(): void
    {
        $this->writeSpan('test-tenant', kind: 2, kindText: 'SERVER', name: 'GET /a');
        $this->writeSpan('test-tenant', kind: 3, kindText: 'CLIENT', name: 'POST /b');
        $this->writeSpan('test-tenant', kind: 1, kindText: 'INTERNAL', name: 'work');

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'any' => [
                    ['column' => 'kind_text', 'op' => 'eq', 'value' => 'SERVER'],
                    ['column' => 'kind_text', 'op' => 'eq', 'value' => 'CLIENT'],
                ],
            ],
        ]);

        $kinds = array_map(static fn (array $r): string => $r['kindText'], $body['member']);
        sort($kinds);
        self::assertSame(['CLIENT', 'SERVER'], $kinds);
    }

    public function testNamePrefixWithNotOnStatus(): void
    {
        $this->writeSpan('test-tenant', name: 'GET /orders/123', kind: 2, kindText: 'SERVER', statusText: 'OK');
        $this->writeSpan('test-tenant', name: 'GET /orders/456', kind: 2, kindText: 'SERVER', statusText: 'ERROR');
        $this->writeSpan('test-tenant', name: 'GET /products/1', kind: 2, kindText: 'SERVER', statusText: 'ERROR');

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'all' => [
                    ['column' => 'name', 'op' => 'prefix', 'value' => 'GET /orders/'],
                    ['not' => ['column' => 'status_text', 'op' => 'eq', 'value' => 'OK']],
                ],
            ],
        ]);

        self::assertCount(1, $body['member']);
        self::assertSame('GET /orders/456', $body['member'][0]['name']);
    }

    public function testBodyLeafRejectedOnTraces(): void
    {
        $this->browser()
            ->post('/v1/traces/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'since' => '2026-05-09T13:00:00Z',
                    'until' => '2026-05-09T15:00:00Z',
                    'criteria' => ['body' => 'contains', 'value' => 'panic'],
                ]),
            ])
            ->assertStatus(400);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postSearch(array $body): array
    {
        $browser = $this->browser()
            ->post('/v1/traces/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/ld+json',
                ],
                'body' => json_encode($body),
            ])
            ->assertStatus(200);

        return json_decode((string) $browser->client()->getResponse()->getContent(), true);
    }

    private function writeSpan(
        string $tenant,
        int $kind = 2,
        string $kindText = 'SERVER',
        string $name = 'GET /',
        string $statusText = 'OK',
    ): void {
        $statusCode = match ($statusText) {
            'OK' => 1,
            'ERROR' => 2,
            default => 0,
        };
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $schema = $catalog->latestFor('traces');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;
        $tenantObj = new Tenant($tenant, $tenant);
        $traceBytes = (string) hex2bin(self::TRACE_HEX);
        $spanBytes = random_bytes(8);

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
                        traceId: $traceBytes,
                        spanId: $spanBytes,
                        parentSpanId: null,
                        traceState: null,
                        flags: null,
                        name: $name,
                        kind: $kind,
                        startTimeUnixNano: $clockUnixNano + 1_000_000,
                        endTimeUnixNano: $clockUnixNano + 5_000_000,
                        attributes: [],
                        events: [],
                        links: [],
                        status: new SpanStatusDto(code: $statusCode, message: null),
                        droppedAttributesCount: 0,
                        droppedEventsCount: 0,
                        droppedLinksCount: 0,
                    )],
                )],
            ),
        ]), $tenantObj);
    }
}
