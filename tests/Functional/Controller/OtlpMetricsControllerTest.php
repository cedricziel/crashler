<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\OtlpMetricsController;
use App\Tests\Support\TempStorageRoot;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\InstrumentationScope;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Metrics\V1\AggregationTemporality;
use Opentelemetry\Proto\Metrics\V1\Metric;
use Opentelemetry\Proto\Metrics\V1\NumberDataPoint;
use Opentelemetry\Proto\Metrics\V1\ResourceMetrics;
use Opentelemetry\Proto\Metrics\V1\ScopeMetrics;
use Opentelemetry\Proto\Metrics\V1\Sum;
use Opentelemetry\Proto\Resource\V1\Resource as OtelResource;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(OtlpMetricsController::class)]
final class OtlpMetricsControllerTest extends KernelTestCase
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

    private function validJsonPayload(): string
    {
        return (string) json_encode([
            'resourceMetrics' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ]],
                'scopeMetrics' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0'],
                    'metrics' => [[
                        'name' => 'http.server.requests',
                        'unit' => '1',
                        'sum' => [
                            'aggregationTemporality' => 2,
                            'isMonotonic' => true,
                            'dataPoints' => [[
                                'timeUnixNano' => '1714752000050000000',
                                'asInt' => '42',
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function validProtobufPayload(): string
    {
        $request = (new ExportMetricsServiceRequest())->setResourceMetrics([
            (new ResourceMetrics())
                ->setResource((new OtelResource())->setAttributes([
                    (new KeyValue())->setKey('service.name')->setValue((new AnyValue())->setStringValue('checkout')),
                ]))
                ->setScopeMetrics([
                    (new ScopeMetrics())
                        ->setScope((new InstrumentationScope())->setName('app')->setVersion('1.0'))
                        ->setMetrics([
                            (new Metric())
                                ->setName('http.server.requests')
                                ->setUnit('1')
                                ->setSum((new Sum())
                                    ->setAggregationTemporality(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE)
                                    ->setIsMonotonic(true)
                                    ->setDataPoints([
                                        (new NumberDataPoint())->setTimeUnixNano(1714752000050000000)->setAsInt(42),
                                    ])),
                        ]),
                ]),
        ]);

        return $request->serializeToString();
    }

    public function testHappyPathJsonReturns200AndWritesParquetFile(): void
    {
        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->validJsonPayload(),
            ])
            ->assertStatus(200)
            ->assertJson()
        ;

        $files = glob($this->tempStorageRoot().'/metrics/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertCount(1, $files, 'exactly one parquet file should be written');
    }

    public function testGzipBodyAccepted(): void
    {
        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                ],
                'body' => (string) gzencode($this->validJsonPayload()),
            ])
            ->assertStatus(200)
        ;

        $files = glob($this->tempStorageRoot().'/metrics/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertCount(1, $files);
    }

    public function testProtobufBodyAccepted(): void
    {
        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/x-protobuf',
                ],
                'body' => $this->validProtobufPayload(),
            ])
            ->assertStatus(200)
            ->assertJson()
        ;

        $files = glob($this->tempStorageRoot().'/metrics/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertCount(1, $files, 'protobuf request must produce one parquet file');
    }

    public function testMissingTokenReturns401(): void
    {
        $this->browser()
            ->post('/v1/metrics', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $this->validJsonPayload(),
            ])
            ->assertStatus(401)
        ;

        self::assertSame([], glob($this->tempStorageRoot().'/metrics/**/*.parquet') ?: []);
    }

    public function testWrongContentTypeReturns415(): void
    {
        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'text/plain',
                ],
                'body' => 'irrelevant',
            ])
            ->assertStatus(415)
            ->assertJson()
        ;
    }

    public function testMalformedJsonReturns400(): void
    {
        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => '{not valid json',
            ])
            ->assertStatus(400)
        ;
    }

    public function testCorruptProtobufBodyReturns400(): void
    {
        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/x-protobuf',
                ],
                'body' => "\x0a\x40\x01\x02\x03",
            ])
            ->assertStatus(400)
        ;
    }

    public function testCompressedBodyOverLimitReturns413(): void
    {
        $body = random_bytes(5 * 1024 * 1024);
        $compressed = (string) gzencode($body);

        if (\strlen($compressed) <= 4 * 1024 * 1024) {
            self::markTestSkipped('Compressed payload under cap; OS gzip compressed too well.');
        }

        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                ],
                'body' => $compressed,
            ])
            ->assertStatus(413)
        ;
    }

    public function testDecompressedBodyOverLimitReturns413(): void
    {
        $payload = str_repeat('A', 18 * 1024 * 1024);
        $compressed = (string) gzencode($payload);
        unset($payload);

        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                ],
                'body' => $compressed,
            ])
            ->assertStatus(413)
        ;
    }

    public function testWriterFailureReturns5xxAndLeavesNoTmpFile(): void
    {
        $junkFile = tempnam(sys_get_temp_dir(), 'crashler-metric-fail-');
        self::assertNotFalse($junkFile);
        $_ENV['APP_SHARE_DIR'] = $junkFile;

        try {
            $this->browser()
                ->post('/v1/metrics', [
                    'headers' => [
                        'Authorization' => 'Bearer '.self::VALID_TOKEN,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => $this->validJsonPayload(),
                ])
                ->assertStatus(500)
            ;

            self::assertSame([], glob($junkFile.'*.tmp') ?: []);
        } finally {
            @unlink($junkFile);
        }
    }

    public function testEmptyDataPointsRequestReturns200WithoutFile(): void
    {
        // Resource block with one Metric carrying an empty dataPoints array.
        $body = (string) json_encode([
            'resourceMetrics' => [[
                'scopeMetrics' => [[
                    'metrics' => [[
                        'name' => 'empty.metric',
                        'gauge' => ['dataPoints' => []],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $this->browser()
            ->post('/v1/metrics', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ])
            ->assertStatus(200)
        ;

        $files = glob($this->tempStorageRoot().'/metrics/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertSame([], $files, 'empty-data-point requests must not write a file');
    }
}
