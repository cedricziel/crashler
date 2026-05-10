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

final class PostLogsSearchTest extends KernelTestCase
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

    public function testSearchReturnsHydraShapeMatchingGet(): void
    {
        $this->writeLogs('test-tenant', ['boom-1', 'boom-2', 'boom-3']);

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'column' => 'resource_service_name', 'op' => 'eq', 'value' => 'checkout',
            ],
        ]);

        self::assertArrayHasKey('member', $body);
        self::assertCount(3, $body['member']);
        self::assertSame('Collection', $body['@type']);
    }

    public function testSearchOrOverServices(): void
    {
        $this->writeLogs('test-tenant', ['x'], 'checkout');
        $this->writeLogs('test-tenant', ['y'], 'payments');
        $this->writeLogs('test-tenant', ['z'], 'internal');

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'any' => [
                    ['column' => 'resource_service_name', 'op' => 'eq', 'value' => 'checkout'],
                    ['column' => 'resource_service_name', 'op' => 'eq', 'value' => 'payments'],
                ],
            ],
        ]);

        $services = array_map(static fn (array $r): string => $r['resourceServiceName'], $body['member']);
        sort($services);
        self::assertSame(['checkout', 'payments'], $services);
    }

    public function testSearchNotExcludesService(): void
    {
        $this->writeLogs('test-tenant', ['x'], 'checkout');
        $this->writeLogs('test-tenant', ['y'], 'internal');

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'not' => ['column' => 'resource_service_name', 'op' => 'eq', 'value' => 'internal'],
            ],
        ]);

        $services = array_map(static fn (array $r): string => $r['resourceServiceName'], $body['member']);
        self::assertSame(['checkout'], $services);
    }

    public function testSearchInOnEventName(): void
    {
        $this->writeLogs('test-tenant', ['m'], 'checkout', eventName: 'login');
        $this->writeLogs('test-tenant', ['n'], 'checkout', eventName: 'logout');
        $this->writeLogs('test-tenant', ['o'], 'checkout', eventName: 'signup');

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'column' => 'event_name', 'op' => 'in', 'value' => ['login', 'signup'],
            ],
        ]);

        $events = array_map(static fn (array $r): string => $r['eventName'], $body['member']);
        sort($events);
        self::assertSame(['login', 'signup'], $events);
    }

    public function testBodyContainsAndNotComposition(): void
    {
        $this->writeLogs('test-tenant', ['panic in checkout'], 'checkout');
        $this->writeLogs('test-tenant', ['ok'], 'checkout');
        $this->writeLogs('test-tenant', ['panic in internal'], 'internal');

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'all' => [
                    ['body' => 'contains', 'value' => 'panic'],
                    ['not' => ['column' => 'resource_service_name', 'op' => 'eq', 'value' => 'internal']],
                ],
            ],
        ]);

        self::assertCount(1, $body['member']);
        self::assertStringContainsString('panic in checkout', $body['member'][0]['bodyJson']);
    }

    public function testCursorRoundTrip(): void
    {
        $bodies = [];
        for ($i = 0; $i < 25; ++$i) {
            $bodies[] = \sprintf('msg-%02d', $i);
        }
        $this->writeLogs('test-tenant', $bodies);

        $criteria = [
            'column' => 'resource_service_name', 'op' => 'eq', 'value' => 'checkout',
        ];
        $reqBody = [
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'limit' => 10,
            'criteria' => $criteria,
        ];

        $page1 = $this->postSearch($reqBody);
        self::assertCount(10, $page1['member']);
        self::assertArrayHasKey('cursor', $page1);
        $cursor = $page1['cursor'];

        $reqBody['cursor'] = $cursor;
        $page2 = $this->postSearch($reqBody);
        self::assertCount(10, $page2['member']);

        $reqBody['cursor'] = $page2['cursor'];
        $page3 = $this->postSearch($reqBody);
        self::assertCount(5, $page3['member']);
        self::assertArrayNotHasKey('cursor', $page3);
    }

    public function testCursorWithMutatedCriteriaRejected(): void
    {
        $this->writeLogs('test-tenant', array_map(static fn ($i) => "x-$i", range(1, 25)));

        $page1 = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'limit' => 10,
            'criteria' => ['column' => 'resource_service_name', 'op' => 'eq', 'value' => 'checkout'],
        ]);
        $cursor = $page1['cursor'];

        $this->browser()
            ->post('/v1/logs/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'since' => '2026-05-09T13:00:00Z',
                    'until' => '2026-05-09T15:00:00Z',
                    'limit' => 10,
                    'cursor' => $cursor,
                    'criteria' => ['column' => 'resource_service_name', 'op' => 'eq', 'value' => 'payments'],
                ]),
            ])
            ->assertStatus(400);
    }

    public function testGetCursorRejectedOnPostSearch(): void
    {
        $this->writeLogs('test-tenant', array_map(static fn ($i) => "x-$i", range(1, 25)));

        // Mint a GET cursor first.
        $getPage1 = $this->browser()
            ->get('/v1/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&limit=10', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);
        $getBody = json_decode((string) $getPage1->client()->getResponse()->getContent(), true);
        $nextUrl = $getBody['view']['next'] ?? null;
        self::assertNotNull($nextUrl);
        // Extract cursor= query parameter from the URL.
        parse_str(parse_url($nextUrl, \PHP_URL_QUERY) ?: '', $params);
        $getCursor = $params['cursor'] ?? null;
        self::assertIsString($getCursor);

        // Replay it on POST search → 400.
        $this->browser()
            ->post('/v1/logs/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'since' => '2026-05-09T13:00:00Z',
                    'until' => '2026-05-09T15:00:00Z',
                    'cursor' => $getCursor,
                    'criteria' => ['column' => 'resource_service_name', 'op' => 'eq', 'value' => 'checkout'],
                ]),
            ])
            ->assertStatus(400);
    }

    public function testWrongContentTypeRejected(): void
    {
        $this->browser()
            ->post('/v1/logs/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'text/plain',
                ],
                'body' => '{"criteria":{"column":"resource_service_name","op":"eq","value":"x"}}',
            ])
            ->assertStatus(415);
    }

    public function testMalformedJsonRejected(): void
    {
        $this->browser()
            ->post('/v1/logs/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => '{not json',
            ])
            ->assertStatus(400);
    }

    public function testMissingCriteriaRejected(): void
    {
        $this->browser()
            ->post('/v1/logs/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => '{"since":"1h"}',
            ])
            ->assertStatus(400);
    }

    public function testMissingBearerRejected(): void
    {
        $this->browser()
            ->post('/v1/logs/search', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"criteria":{"column":"resource_service_name","op":"eq","value":"x"}}',
            ])
            ->assertStatus(401);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postSearch(array $body): array
    {
        $browser = $this->browser()
            ->post('/v1/logs/search', [
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

    /**
     * @param list<string> $bodies
     */
    private function writeLogs(string $tenant, array $bodies, string $service = 'checkout', ?string $eventName = null): void
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
            $attrs = [];
            if (null !== $eventName) {
                $attrs[] = new KeyValueDto('event.name', AnyValueDto::string($eventName));
            }
            $records[] = new LogRecordDto(
                timeUnixNano: $clockUnixNano + $i * 1_000_000,
                observedTimeUnixNano: null,
                severityNumber: 9,
                severityText: 'INFO',
                body: AnyValueDto::string($body),
                attributes: $attrs,
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
