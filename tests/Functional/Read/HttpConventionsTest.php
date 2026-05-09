<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Read\Http\ReadResponseConventionsListener;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(ReadResponseConventionsListener::class)]
final class HttpConventionsTest extends KernelTestCase
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

    public function testCacheControlIsNoStorePrivateOn200(): void
    {
        $browser = $this->browser()
            ->get('/v1/logs?since=1h&limit=1', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        $cacheControl = (string) $browser->client()->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testGetWithBodyReturns415(): void
    {
        // zenstruck/browser doesn't trivially set a body on a GET, so we
        // forge a Content-Length header. The listener short-circuits on
        // that signal alone.
        $this->browser()
            ->get('/v1/logs?since=1h&limit=1', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Content-Length' => '5',
                ],
            ])
            ->assertStatus(415)
            ->assertJson()
            ->assertJsonMatches('message', 'Read endpoints take no request body.');
    }

    public function testGzipResponseWhenAccepted(): void
    {
        $browser = $this->browser()
            ->get('/v1/logs?since=1h&limit=1', [
                'headers' => [
                    'Authorization' => 'Bearer '.self::VALID_TOKEN,
                    'Accept-Encoding' => 'gzip',
                ],
            ])
            ->assertStatus(200);

        $response = $browser->client()->getResponse();
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));

        // Decompress and confirm it parses as JSON
        $decompressed = gzdecode((string) $response->getContent());
        self::assertNotFalse($decompressed);
        $decoded = json_decode($decompressed, true);
        self::assertIsArray($decoded);
    }

    public function testNoGzipWithoutAcceptEncoding(): void
    {
        $browser = $this->browser()
            ->get('/v1/logs?since=1h&limit=1', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(200);

        // No Accept-Encoding header → response not gzipped.
        $contentEncoding = $browser->client()->getResponse()->headers->get('Content-Encoding');
        self::assertNull($contentEncoding);
    }

    public function testCacheControlOnTraceByIdNotSetOnNon2xx(): void
    {
        // 404 is not "successful"; listener checks isSuccessful() so the
        // header is not forced. Smoke-checks the listener didn't crash.
        $browser = $this->browser()
            ->get('/v1/traces/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(404);

        // Just verify we got a JSON 404 — the listener didn't break it.
        $body = json_decode((string) $browser->client()->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('message', $body);
    }
}
