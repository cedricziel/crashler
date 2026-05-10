<?php

declare(strict_types=1);

namespace App\Storage;

use App\Schema\SchemaCompiler;
use App\Schema\SchemaDefinition;
use Flow\Parquet\Option;
use Flow\Parquet\Options;
use Flow\Parquet\ParquetFile\Compressions;
use Flow\Parquet\Writer;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes a single Parquet file via {@see Writer} to a `.tmp` path, fsyncs the
 * descriptor, then atomically renames to the final path. On any failure the
 * `.tmp` is removed and the exception is re-raised so the request layer can
 * surface a 5xx with no orphan on disk.
 *
 * Every row is augmented with the universal `_schema_version` and `_schema_id`
 * columns derived from the SchemaDefinition; callers do not need to inject
 * these themselves.
 */
final class ParquetFileWriter implements WritesParquetFiles
{
    /** Default Parquet row-group size cap; matches Option::ROW_GROUP_SIZE_BYTES. */
    private const int ROW_GROUP_SIZE_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly SchemaDefinition $definition,
        private readonly Compressions $compression,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     */
    public function writeAndCommit(string $finalPath, iterable $rows): void
    {
        $tmpPath = $finalPath.'.tmp';
        $schema = SchemaCompiler::toFlowSchema($this->definition);
        $version = $this->definition->version;
        $id = $this->definition->id();

        try {
            $writer = new Writer(
                compression: $this->compression,
                options: (new Options())->set(Option::ROW_GROUP_SIZE_BYTES, self::ROW_GROUP_SIZE_BYTES),
            );

            try {
                $writer->open($tmpPath, $schema);
                $writer->writeBatch($this->augmentRows($rows, $version, $id));
            } finally {
                if ($writer->isOpen()) {
                    $writer->close();
                }
            }

            $this->fsyncFile($tmpPath);

            try {
                $this->filesystem->rename($tmpPath, $finalPath, overwrite: false);
            } catch (IOExceptionInterface $e) {
                throw new \RuntimeException(\sprintf(
                    'Failed to rename "%s" -> "%s": %s',
                    $tmpPath,
                    $finalPath,
                    $e->getMessage(),
                ), previous: $e);
            }
        } catch (\Throwable $e) {
            if ($this->filesystem->exists($tmpPath)) {
                $this->filesystem->remove($tmpPath);
            }
            throw $e;
        }
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function augmentRows(iterable $rows, int $version, string $id): \Generator
    {
        foreach ($rows as $row) {
            $row[SchemaCompiler::COLUMN_SCHEMA_VERSION] = $version;
            $row[SchemaCompiler::COLUMN_SCHEMA_ID] = $id;
            yield $row;
        }
    }

    private function fsyncFile(string $path): void
    {
        $fh = @fopen($path, 'r+');
        if (false === $fh) {
            return; // best effort; rename will still attempt
        }
        try {
            @fsync($fh);
        } finally {
            fclose($fh);
        }
    }
}
