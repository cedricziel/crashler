<?php

declare(strict_types=1);

namespace App\Storage;

use Flow\Parquet\ParquetFile\Compressions;

/**
 * Resolves the CRASHLER_PARQUET_COMPRESSION env value to a flow-php Compressions
 * enum, verifying the codec's required PHP extension is loaded. Fails fast at
 * construction-time so a misconfigured deployment does not start serving traffic.
 *
 * The extensionLoaded callable seam exists so tests can simulate missing
 * extensions without actually unloading them from PHP.
 */
final class ParquetCompression
{
    /** @var \Closure(string): bool */
    private \Closure $extensionLoaded;

    /**
     * @param (callable(string): bool)|null $extensionLoaded test seam; defaults to {@see extension_loaded()}
     */
    public function __construct(?callable $extensionLoaded = null)
    {
        $this->extensionLoaded = \Closure::fromCallable($extensionLoaded ?? extension_loaded(...));
    }

    public function resolve(string $name): Compressions
    {
        $codec = match ($name) {
            'GZIP' => Compressions::GZIP,
            'ZSTD' => Compressions::ZSTD,
            'SNAPPY' => Compressions::SNAPPY,
            'BROTLI' => Compressions::BROTLI,
            'LZ4' => Compressions::LZ4,
            'LZ4_RAW' => Compressions::LZ4_RAW,
            'UNCOMPRESSED' => Compressions::UNCOMPRESSED,
            default => throw new \InvalidArgumentException(\sprintf(
                'Unknown Parquet compression codec "%s"; must be one of GZIP, ZSTD, SNAPPY, BROTLI, LZ4, LZ4_RAW, UNCOMPRESSED.',
                $name,
            )),
        };

        $required = self::requiredExtensionFor($codec);
        if (null !== $required && !($this->extensionLoaded)($required)) {
            throw new \RuntimeException(\sprintf(
                'Parquet compression codec "%s" requires the PHP extension "%s", which is not loaded.',
                $name,
                $required,
            ));
        }

        return $codec;
    }

    private static function requiredExtensionFor(Compressions $codec): ?string
    {
        return match ($codec) {
            Compressions::ZSTD => 'zstd',
            Compressions::BROTLI => 'brotli',
            Compressions::LZ4, Compressions::LZ4_RAW => 'lz4',
            // GZIP is in stock PHP (zlib is bundled). SNAPPY works in pure PHP
            // (slowly) without ext-snappy, so we don't gate on it. UNCOMPRESSED
            // needs nothing.
            default => null,
        };
    }
}
