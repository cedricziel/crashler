<?php

declare(strict_types=1);

namespace App\Storage;

interface WritesParquetFiles
{
    /**
     * Write the given rows into a single Parquet file at $finalPath, atomically.
     * On failure, no file (and no .tmp file) remains at the destination.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function writeAndCommit(string $finalPath, iterable $rows): void;
}
