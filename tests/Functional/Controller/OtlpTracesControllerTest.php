<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\OtlpTracesController;
use App\Tests\Support\TempStorageRoot;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\InstrumentationScope;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Resource\V1\Resource as OtelResource;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Span\SpanKind;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(OtlpTracesController::class)]
final class OtlpTracesControllerTest extends KernelTestCase
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
            'resourceSpans' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ]],
                'scopeSpans' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0'],
                    'spans' => [[
                        'traceId' => '5b8aa5a2d2c872e8321cf37308d69df2',
                        'spanId' => '051581bf3cb55c13',
                        'name' => 'GET /orders/:id',
                        'kind' => 2,
                        'startTimeUnixNano' => '1714752000000000000',
                        'endTimeUnixNano' => '1714752000050000000',
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function validProtobufPayload(): string
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');

        $request = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())
                ->setResource((new OtelResource())->setAttributes([
                    (new KeyValue())->setKey('service.name')->setValue((new AnyValue())->setStringValue('checkout')),
                ]))
                ->setScopeSpans([
                    (new ScopeSpans())
                        ->setScope((new InstrumentationScope())->setName('app')->setVersion('1.0'))
                        ->setSpans([
                            (new Span())
                                ->setTraceId($traceId)
                                ->setSpanId($spanId)
                                ->setName('GET /orders/:id')
                                ->setKind(SpanKind::SPAN_KIND_SERVER)
                                ->setStartTimeUnixNano(1714752000000000000)
                                ->setEndTimeUnixNano(1714752000050000000),
                        ]),
                ]),
        ]);

        return $request->serializeToString();
    }

    public function testHappyPathJsonReturns200AndWritesParquetFile(): void
    {
        $this->browser()
            ->post('/v1/traces', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->validJsonPayload(),
            ])
            ->assertStatus(200)
            ->assertJson()
        ;

        $files = glob($this->tempStorageRoot().'/traces/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertCount(1, $files, 'exactly one parquet file should be written');
    }

    public function testGzipBodyAccepted(): void
    {
        $this->browser()
            ->post('/v1/traces', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                ],
                'body' => (string) gzencode($this->validJsonPayload()),
            ])
            ->assertStatus(200)
        ;

        $files = glob($this->tempStorageRoot().'/traces/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertCount(1, $files);
    }

    public function testProtobufBodyAccepted(): void
    {
        $this->browser()
            ->post('/v1/traces', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/x-protobuf',
                ],
                'body' => $this->validProtobufPayload(),
            ])
            ->assertStatus(200)
            ->assertJson()
        ;

        $files = glob($this->tempStorageRoot().'/traces/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertCount(1, $files, 'protobuf request must produce one parquet file');
    }

    public function testMissingTokenReturns401(): void
    {
        $this->browser()
            ->post('/v1/traces', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $this->validJsonPayload(),
            ])
            ->assertStatus(401)
        ;

        $files = glob($this->tempStorageRoot().'/traces/**/*.parquet') ?: [];
        self::assertSame([], $files);
    }

    public function testWrongContentTypeReturns415(): void
    {
        $this->browser()
            ->post('/v1/traces', [
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
            ->post('/v1/traces', [
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
            ->post('/v1/traces', [
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
            ->post('/v1/traces', [
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
            ->post('/v1/traces', [
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
        // Point storage root at a regular file so the partition mkdir fails
        // inside the ingest service and the pipeline catches the throwable.
        $junkFile = tempnam(sys_get_temp_dir(), 'crashler-trace-fail-');
        self::assertNotFalse($junkFile);
        $_ENV['APP_SHARE_DIR'] = $junkFile;

        try {
            $this->browser()
                ->post('/v1/traces', [
                    'headers' => [
                        'Authorization' => 'Bearer '.self::VALID_TOKEN,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => $this->validJsonPayload(),
                ])
                ->assertStatus(500)
            ;

            // No .tmp file should be left dangling under the configured root.
            self::assertSame([], glob($junkFile.'*.tmp') ?: []);
        } finally {
            @unlink($junkFile);
        }
    }
}
