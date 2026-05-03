<?php

declare(strict_types=1);

namespace App\Storage;

use Flow\Parquet\Option;
use Flow\Parquet\Options;
use Flow\Parquet\ParquetFile\Compressions;
use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\Writer;

/**
 * Writes a single Parquet file via {@see Writer} to a `.tmp` path, fsyncs the
 * descriptor, then atomically renames to the final path. On any failure the
 * `.tmp` is removed and the exception is re-raised so the request layer can
 * surface a 5xx with no orphan on disk.
 */
final class ParquetFileWriter
{
    /** Default Parquet row-group size cap; matches Option::ROW_GROUP_SIZE_BYTES. */
    private const int ROW_GROUP_SIZE_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly Schema $schema,
        private readonly Compressions $compression,
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

            if (!@rename($tmpPath, $finalPath)) {
                $error = error_get_last()['message'] ?? 'unknown error';
                throw new \RuntimeException(\sprintf(
                    'Failed to rename "%s" -> "%s": %s',
                    $tmpPath,
                    $finalPath,
                    $error,
                ));
            }
        } catch (\Throwable $e) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
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
