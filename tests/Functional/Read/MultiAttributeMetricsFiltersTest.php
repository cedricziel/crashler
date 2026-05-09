<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Metrics\MetricsIngestService;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\MetricDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Dto\NumberDataPointDto;
use App\Otlp\Dto\ResourceMetricsDto;
use App\Otlp\Dto\ScopeMetricsDto;
use App\Read\State\MetricsStateProvider;
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

#[CoversClass(MetricsStateProvider::class)]
final class MultiAttributeMetricsFiltersTest extends KernelTestCase
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

    public function testTwoAttributeFiltersComposeWithAndOnMetrics(): void
    {
        // Three rows, one per combination of {k8s.cluster, region}:
        //   row A: cluster=prod AND region=eu-west-1  → matches both
        //   row B: cluster=prod alone                 → matches only first
        //   row C: region=eu-west-1 alone             → matches only second
        $this->writeMetric(name: 'http.server.duration.a', cluster: 'prod', region: 'eu-west-1');
        $this->writeMetric(name: 'http.server.duration.b', cluster: 'prod', region: 'us-east-1');
        $this->writeMetric(name: 'http.server.duration.c', cluster: 'staging', region: 'eu-west-1');

        $browser = $this->browser()
            ->get('/v1/metrics?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&attribute.k8s.cluster=prod&attribute.region=eu-west-1', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $body = json_decode((string) $browser->client()->getResponse()->getContent(), true);
        $rows = $body['member'];

        self::assertCount(1, $rows, 'only the doubly-matching metric row must be returned');
        self::assertSame('http.server.duration.a', $rows[0]['metricName']);
    }

    private function writeMetric(string $name, string $cluster, string $region): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $schema = $catalog->latestFor('metrics');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;

        $service = new MetricsIngestService(
            new ParquetFileWriter($schema, Compressions::GZIP),
            new PartitionPathResolver($clock, new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))), $this->tempStorageRoot()),
            new AttributeColumnExtractor($schema),
        );

        $service->write(new ExportMetricsServiceRequestDto([
            new ResourceMetricsDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
                scopeMetrics: [new ScopeMetricsDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    metrics: [new MetricDto(
                        name: $name,
                        unit: 's',
                        description: null,
                        type: MetricType::Gauge,
                        aggregationTemporality: 2,
                        isMonotonic: false,
                        numberDataPoints: [new NumberDataPointDto(
                            startTimeUnixNano: $clockUnixNano,
                            timeUnixNano: $clockUnixNano + random_int(0, 999_999),
                            valueDouble: 1.0,
                            valueInt: null,
                            attributes: [
                                new KeyValueDto('k8s.cluster', AnyValueDto::string($cluster)),
                                new KeyValueDto('region', AnyValueDto::string($region)),
                            ],
                            exemplars: [],
                            flags: null,
                        )],
                        histogramDataPoints: [],
                        exponentialHistogramDataPoints: [],
                        summaryDataPoints: [],
                    )],
                )],
            ),
        ]), new Tenant('test-tenant', 'test-tenant'));
    }
}
