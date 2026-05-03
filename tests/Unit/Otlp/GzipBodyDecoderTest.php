<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\Exception\OtlpPayloadTooLargeException;
use App\Otlp\GzipBodyDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GzipBodyDecoder::class)]
#[CoversClass(OtlpPayloadTooLargeException::class)]
final class GzipBodyDecoderTest extends TestCase
{
    private GzipBodyDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new GzipBodyDecoder();
    }

    public function testRoundTripsKnownString(): void
    {
        $payload = 'the quick brown fox jumps over the lazy dog';
        $compressed = gzencode($payload);
        self::assertNotFalse($compressed);

        $decoded = $this->decoder->decode($compressed, maxDecompressedBytes: 1024);

        self::assertSame($payload, $decoded);
    }

    public function testRejectsOversizedOutputMidStream(): void
    {
        $payload = str_repeat('A', 1024 * 1024); // 1 MiB
        $compressed = gzencode($payload);
        self::assertNotFalse($compressed);

        $this->expectException(OtlpPayloadTooLargeException::class);

        $this->decoder->decode($compressed, maxDecompressedBytes: 1024);
    }

    public function testRejectsCorruptGzipBytes(): void
    {
        $this->expectException(OtlpDecodeException::class);

        $this->decoder->decode('this is not gzip', maxDecompressedBytes: 1024);
    }

    public function testRejectsEmptyInput(): void
    {
        $this->expectException(OtlpDecodeException::class);

        $this->decoder->decode('', maxDecompressedBytes: 1024);
    }

    public function testAcceptsExactlyAtTheLimit(): void
    {
        $payload = str_repeat('a', 256);
        $compressed = gzencode($payload);
        self::assertNotFalse($compressed);

        $decoded = $this->decoder->decode($compressed, maxDecompressedBytes: 256);

        self::assertSame($payload, $decoded);
    }

    public function testStreamingDecodeDoesNotAllocateBeyondLimit(): void
    {
        // 8 MiB of repeating bytes compresses to ~8 KiB. We attempt to decode
        // it with a 64 KiB limit. The decoder must trip the limit during the
        // streaming inflate, not after fully decompressing into memory.
        $payload = str_repeat('A', 8 * 1024 * 1024);
        $compressed = gzencode($payload);
        self::assertNotFalse($compressed);

        $startMem = memory_get_usage();

        try {
            $this->decoder->decode($compressed, maxDecompressedBytes: 64 * 1024);
            self::fail('Expected OtlpPayloadTooLargeException');
        } catch (OtlpPayloadTooLargeException) {
            // expected
        }

        $delta = memory_get_usage() - $startMem;

        // Allow up to 256 KiB of working memory above the limit (ZLib
        // intermediate buffers, PHP allocator slack). If the implementation
        // accidentally fully decompressed the 8 MiB payload, this check fails.
        self::assertLessThan(256 * 1024, $delta, 'gzip decoder must enforce the limit incrementally');
    }
}
