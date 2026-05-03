<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\ParquetCompression;
use Flow\Parquet\ParquetFile\Compressions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParquetCompression::class)]
final class ParquetCompressionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: Compressions}>
     */
    public static function knownCodecProvider(): iterable
    {
        yield 'GZIP' => ['GZIP', Compressions::GZIP];
        yield 'ZSTD' => ['ZSTD', Compressions::ZSTD];
        yield 'SNAPPY' => ['SNAPPY', Compressions::SNAPPY];
        yield 'BROTLI' => ['BROTLI', Compressions::BROTLI];
        yield 'LZ4' => ['LZ4', Compressions::LZ4];
        yield 'LZ4_RAW' => ['LZ4_RAW', Compressions::LZ4_RAW];
        yield 'UNCOMPRESSED' => ['UNCOMPRESSED', Compressions::UNCOMPRESSED];
    }

    #[DataProvider('knownCodecProvider')]
    public function testKnownCodecResolvesToFlowEnum(string $name, Compressions $expected): void
    {
        $resolver = new ParquetCompression(extensionLoaded: static fn () => true);

        self::assertSame($expected, $resolver->resolve($name));
    }

    public function testUnknownCodecThrows(): void
    {
        $resolver = new ParquetCompression(extensionLoaded: static fn () => true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown.*compression/i');

        $resolver->resolve('lzma');
    }

    public function testZstdRequiresExtZstd(): void
    {
        $resolver = new ParquetCompression(
            extensionLoaded: static fn (string $ext): bool => 'zstd' !== $ext,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/zstd/i');

        $resolver->resolve('ZSTD');
    }

    public function testBrotliRequiresExtBrotli(): void
    {
        $resolver = new ParquetCompression(
            extensionLoaded: static fn (string $ext): bool => 'brotli' !== $ext,
        );

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('BROTLI');
    }

    public function testLz4RequiresExtLz4(): void
    {
        $resolver = new ParquetCompression(
            extensionLoaded: static fn (string $ext): bool => 'lz4' !== $ext,
        );

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('LZ4');
    }

    public function testGzipDoesNotRequireAnyExtension(): void
    {
        $resolver = new ParquetCompression(
            extensionLoaded: static fn (string $ext): bool => false,
        );

        // Stock PHP zlib — no ext check needed.
        self::assertSame(Compressions::GZIP, $resolver->resolve('GZIP'));
    }

    public function testUncompressedDoesNotRequireAnyExtension(): void
    {
        $resolver = new ParquetCompression(
            extensionLoaded: static fn (string $ext): bool => false,
        );

        self::assertSame(Compressions::UNCOMPRESSED, $resolver->resolve('UNCOMPRESSED'));
    }

    public function testIsCaseSensitive(): void
    {
        // The env value is meant to be uppercase; lowercase should not slip through.
        $resolver = new ParquetCompression(extensionLoaded: static fn () => true);

        $this->expectException(\InvalidArgumentException::class);

        $resolver->resolve('gzip');
    }
}
