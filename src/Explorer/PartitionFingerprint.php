<?php

declare(strict_types=1);

namespace App\Explorer;

/**
 * Hash of the file count + max(mtime) across a list of partition globs.
 *
 * Used as the cache-key suffix for resolver caches. When a new ingest
 * writes a parquet file into a partition the fingerprint changes, so
 * the cache entry for that (tenant, signal, window) tuple naturally
 * misses on the next read — fresh scan, then cached again.
 *
 * Computing the fingerprint is O(files in the partition globs) but
 * each entry is just a stat() call — orders of magnitude cheaper than
 * the parquet scan it gates.
 */
final class PartitionFingerprint
{
    /**
     * @param list<string> $partitionGlobs
     */
    public static function of(array $partitionGlobs): string
    {
        $files = [];
        foreach ($partitionGlobs as $glob) {
            $matches = glob($glob, \GLOB_NOSORT);
            if (false === $matches) {
                continue;
            }
            foreach ($matches as $f) {
                $files[] = $f;
            }
        }

        $count = \count($files);
        $maxMtime = 0;
        foreach ($files as $f) {
            $m = @filemtime($f);
            if (false !== $m && $m > $maxMtime) {
                $maxMtime = $m;
            }
        }

        return \sprintf('%d.%d', $count, $maxMtime);
    }
}
