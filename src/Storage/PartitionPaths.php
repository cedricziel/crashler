<?php

declare(strict_types=1);

namespace App\Storage;

final readonly class PartitionPaths
{
    public function __construct(
        public string $finalPath,
        public string $tmpPath,
        public string $partitionDir,
    ) {
    }
}
