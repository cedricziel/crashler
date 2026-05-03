<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Wires the writer at boot with the configured compression codec, failing
 * fast if the codec's PHP extension is missing.
 */
final class ParquetFileWriterFactory
{
    public function __construct(
        private readonly ParquetCompression $compression,
        private readonly string $configuredCompressionName,
    ) {
    }

    public function create(): ParquetFileWriter
    {
        return new ParquetFileWriter(
            ParquetSchema::definition(),
            $this->compression->resolve($this->configuredCompressionName),
        );
    }
}
