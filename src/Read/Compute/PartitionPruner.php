<?php

declare(strict_types=1);

namespace App\Read\Compute;

use App\Read\Criteria\TimeWindow;

/**
 * Translates a `[since, until]` time window into the set of Parquet partition
 * file globs that fall inside the window. Each glob has the shape:
 *
 *   <storage-root>/<signal>/<tenant_slug>/date=YYYY-MM-DD/hour=HH/part-*.parquet
 *
 * The partition layout is keyed by ingest wall-clock time (UTC), matching the
 * write side's `PartitionPathResolver`. Pruning by hour is the load-bearing
 * push-down — it reduces the input set before the scanner opens any file.
 *
 * The pruner does NOT probe the filesystem: it computes the *expected* glob
 * set and lets the scanner deal with directories that don't exist (no rows).
 */
final readonly class PartitionPruner
{
    public function __construct(
        private string $storageRoot,
    ) {
    }

    /**
     * @return list<string> partition file globs in chronological order
     */
    public function globsFor(string $tenantSlug, string $signal, TimeWindow $window): array
    {
        $sinceSeconds = (int) ($window->sinceUnixNano / 1_000_000_000);
        $untilSeconds = (int) ($window->untilUnixNano / 1_000_000_000);

        // Truncate `since` down to the hour so a query for 14:37 still picks
        // up partition `hour=14`. `until` is inclusive of the hour it falls
        // in — same reasoning.
        $startHour = $sinceSeconds - ($sinceSeconds % 3600);
        $endHour = $untilSeconds - ($untilSeconds % 3600);

        $root = rtrim($this->storageRoot, '/');
        $base = "$root/$signal/$tenantSlug";

        $globs = [];
        for ($t = $startHour; $t <= $endHour; $t += 3600) {
            $dt = (new \DateTimeImmutable('@'.$t))->setTimezone(new \DateTimeZone('UTC'));
            $date = $dt->format('Y-m-d');
            $hour = $dt->format('H');
            $globs[] = "$base/date=$date/hour=$hour/part-*.parquet";
        }

        return $globs;
    }
}
