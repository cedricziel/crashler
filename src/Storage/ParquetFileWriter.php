<?php

declare(strict_types=1);

namespace App\Storage;

use Flow\Parquet\Option;
use Flow\Parquet\Options;
use Flow\Parquet\ParquetFile\Compressions;
use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\Writer;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes a single Parquet file via {@see Writer} to a `.tmp` path, fsyncs the
 * descriptor, then atomically renames to the final path. On any failure the
 * `.tmp` is removed and the exception is re-raised so the request layer can
 * surface a 5xx with no orphan on disk.
 */
final class ParquetFileWriter implements WritesParquetFiles
{
    /** Default Parquet row-group size cap; matches Option::ROW_GROUP_SIZE_BYTES. */
    private const int ROW_GROUP_SIZE_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly Schema $schema,
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

        try {
            $writer = new Writer(
                compression: $this->compression,
                options: (new Options())->set(Option::ROW_GROUP_SIZE_BYTES, self::ROW_GROUP_SIZE_BYTES),
            );

            try {
                $writer->open($tmpPath, $this->schema);
                $writer->writeBatch($rows);
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

    private function fsyncFile(string $path): void
    {
        $fh = @fopen($path, 'r+b');
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
