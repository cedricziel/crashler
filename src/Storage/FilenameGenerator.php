<?php

declare(strict_types=1);

namespace App\Storage;

interface FilenameGenerator
{
    /**
     * Produce a filesystem-safe identifier used as the per-file portion of a
     * Parquet path. Must be lexicographically monotonic across consecutive calls
     * within the same process so directory listings sort by creation order.
     */
    public function generate(): string;
}
