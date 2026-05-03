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

    /**
     * @param non-empty-string $signalSubdir signal-level subdirectory
     *                                       (e.g. 'logs', 'traces', 'metrics')
     */
    public function resolve(Tenant $tenant, string $signalSubdir): PartitionPaths
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $date = $now->format('Y-m-d');
        $hour = $now->format('H');

        $partitionDir = \sprintf(
            '%s/%s/%s/date=%s/hour=%s',
            rtrim($this->storageRoot, '/'),
            $signalSubdir,
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
