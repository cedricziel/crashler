<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\OtlpLogsController;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(OtlpLogsController::class)]
final class OtlpLogsControllerTest extends KernelTestCase
{
    use HasBrowser;
    use TempStorageRoot;

    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        // Force every test to use a unique storage root parameter so files
        // produced by one test don't bleed into another's assertions.
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    private function validRequestPayload(): string
    {
        return (string) json_encode([
            'resourceLogs' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ]],
                'scopeLogs' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0'],
                    'logRecords' => [[
                        'timeUnixNano' => '1714752000000000000',
                        'severityNumber' => 9,
                        'severityText' => 'INFO',
                        'body' => ['stringValue' => 'hello'],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    public function testHappyPathReturns200AndWritesParquetFile(): void
    {
        $this->browser()
            ->post('/v1/logs', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->validRequestPayload(),
            ])
            ->assertStatus(200)
            ->assertJson()
        ;

        $files = glob($this->tempStorageRoot().'/logs/test-tenant/date=*/hour=*/part-*.parquet') ?: [];
        self::assertCount(1, $files, 'exactly one parquet file should be written');
    }

    public function testGzipBodyAccepted(): void
    {
        $this->browser()
            ->post('/v1/logs', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                ],
                'body' => (string) gzencode($this->validRequestPayload()),
            ])
            ->assertStatus(200)
        ;
    }

    public function testMissingTokenReturns401AndDoesNotWriteFile(): void
    {
        $this->browser()
            ->post('/v1/logs', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $this->validRequestPayload(),
            ])
            ->assertStatus(401)
        ;

        $files = glob($this->tempStorageRoot().'/logs/**/*.parquet') ?: [];
        self::assertSame([], $files);
    }

    public function testWrongContentTypeReturns415(): void
    {
        $this->browser()
            ->post('/v1/logs', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/x-protobuf',
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
            ->post('/v1/logs', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => '{not valid json',
            ])
            ->assertStatus(400)
        ;
    }

    public function testSchemaMismatchReturns400(): void
    {
        $this->browser()
            ->post('/v1/logs', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Type' => 'application/json',
                ],
                'body' => '{"resourceLogs": "should be an array"}',
            ])
            ->assertStatus(400)
        ;
    }

    public function testCompressedBodyOverLimitReturns413(): void
    {
        // Default max compressed is 4 MiB. Random bytes don't compress well,
        // so 5 MiB of random input will gzip to ~5 MiB and trip the cap.
        $body = random_bytes(5 * 1024 * 1024);
        $compressed = (string) gzencode($body);

        if (\strlen($compressed) <= 4 * 1024 * 1024) {
            self::markTestSkipped('Compressed payload under cap; OS gzip compressed too well.');
        }

        $this->browser()
            ->post('/v1/logs', [
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
        // Default max decompressed is 16 MiB. ~18 MiB of A-repeats compresses
        // to ~17 KiB so it passes the compressed cap, but the streaming
        // decoder must trip the decompressed cap mid-stream.
        $payload = str_repeat('A', 18 * 1024 * 1024);
        $compressed = (string) gzencode($payload);
        unset($payload);

        $this->browser()
            ->post('/v1/logs', [
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
}
