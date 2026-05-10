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

final class PostMetricsSearchTest extends KernelTestCase
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

    public function testMetricTypeOrComposition(): void
    {
        $this->writeMetric('test-tenant', name: 'http.server.duration', type: MetricType::Sum);
        $this->writeMetric('test-tenant', name: 'process.cpu.utilization', type: MetricType::Gauge);
        $this->writeMetric('test-tenant', name: 'http.server.body.size', type: MetricType::Histogram);

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'any' => [
                    ['column' => 'metric_type', 'op' => 'eq', 'value' => 'SUM'],
                    ['column' => 'metric_type', 'op' => 'eq', 'value' => 'GAUGE'],
                ],
            ],
        ]);

        $types = array_map(static fn (array $r): string => $r['metricType'], $body['member']);
        sort($types);
        self::assertSame(['GAUGE', 'SUM'], $types);
    }

    public function testMetricNameInList(): void
    {
        $this->writeMetric('test-tenant', name: 'http.server.duration', type: MetricType::Sum);
        $this->writeMetric('test-tenant', name: 'http.client.duration', type: MetricType::Sum);
        $this->writeMetric('test-tenant', name: 'process.cpu.utilization', type: MetricType::Gauge);

        $body = $this->postSearch([
            'since' => '2026-05-09T13:00:00Z',
            'until' => '2026-05-09T15:00:00Z',
            'criteria' => [
                'column' => 'metric_name',
                'op' => 'in',
                'value' => ['http.server.duration', 'http.client.duration'],
            ],
        ]);

        $names = array_map(static fn (array $r): string => $r['metricName'], $body['member']);
        sort($names);
        self::assertSame(['http.client.duration', 'http.server.duration'], $names);
    }

    public function testBodyLeafRejectedOnMetrics(): void
    {
        $this->browser()
            ->post('/v1/metrics/search', [
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
            ->post('/v1/metrics/search', [
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

    private function writeMetric(string $tenant, string $name, MetricType $type): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $schema = $catalog->latestFor('metrics');
        $clock = new MockClock('2026-05-09 14:30:00 UTC');
        $clockUnixNano = (int) (new \DateTimeImmutable('2026-05-09 14:30:00 UTC'))->format('U') * 1_000_000_000;
        $tenantObj = new Tenant($tenant, $tenant);

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
                        type: $type,
                        aggregationTemporality: 2,
                        isMonotonic: MetricType::Sum === $type,
                        numberDataPoints: [new NumberDataPointDto(
                            startTimeUnixNano: $clockUnixNano,
                            timeUnixNano: $clockUnixNano + 1_000_000,
                            valueDouble: 1.0,
                            valueInt: null,
                            attributes: [],
                            exemplars: [],
                            flags: null,
                        )],
                        histogramDataPoints: [],
                        exponentialHistogramDataPoints: [],
                        summaryDataPoints: [],
                    )],
                )],
            ),
        ]), $tenantObj);
    }
}
