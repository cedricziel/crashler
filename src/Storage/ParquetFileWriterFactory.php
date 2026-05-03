<?php

declare(strict_types=1);

namespace App\Storage;

use App\Schema\SchemaCatalog;

/**
 * Wires the writer at boot with the configured compression codec and the
 * latest schema for a given signal. Fails fast if the codec's PHP extension
 * is missing or the requested signal has no loaded schema.
 */
final class ParquetFileWriterFactory
{
    public function __construct(
        private readonly ParquetCompression $compression,
        private readonly string $configuredCompressionName,
        private readonly SchemaCatalog $catalog,
    ) {
    }

    public function create(string $signal = 'logs'): ParquetFileWriter
    {
        return new ParquetFileWriter(
            $this->catalog->latestFor($signal),
            $this->compression->resolve($this->configuredCompressionName),
        );
    }
}
