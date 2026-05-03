<?php

declare(strict_types=1);

namespace App\Storage;

use App\Tenancy\Tenant;
use Psr\Clock\ClockInterface;

final class PartitionPathResolver
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly FilenameGenerator $filenames,
        private readonly string $storageRoot,
    ) {
    }

    public function resolve(Tenant $tenant): PartitionPaths
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $date = $now->format('Y-m-d');
        $hour = $now->format('H');

        $partitionDir = \sprintf(
            '%s/logs/%s/date=%s/hour=%s',
            rtrim($this->storageRoot, '/'),
            $tenant->slug,
            $date,
            $hour,
        );
        $final = \sprintf('%s/part-%s.parquet', $partitionDir, $this->filenames->generate());

        return new PartitionPaths(
            finalPath: $final,
            tmpPath: $final.'.tmp',
            partitionDir: $partitionDir,
        );
    }
}
