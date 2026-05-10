<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Criteria\TimeWindow;

/**
 * Snaps a TimeWindow's nanosecond bounds to a coarse grid so that
 * consecutive requests (with `now()` advancing per-request) share a
 * stable bucketed window — and therefore a stable cache key.
 *
 * The data scanned by the resolver uses the BUCKETED window, not the
 * raw user input. This matters: if cache key were bucketed but the
 * scan used the raw window, two requests with bucketed-equal but
 * raw-different windows would race to write inconsistent data into
 * the same key.
 *
 * Bucket width controls the cache-effectiveness vs staleness trade-off.
 * 60s default lets adjacent page loads within a minute share results.
 */
final readonly class WindowBucket
{
    public function __construct(private int $bucketSeconds = 60)
    {
    }

    public function snap(TimeWindow $window): TimeWindow
    {
        $bucketNs = $this->bucketSeconds * 1_000_000_000;

        return new TimeWindow(
            sinceUnixNano: intdiv($window->sinceUnixNano, $bucketNs) * $bucketNs,
            untilUnixNano: intdiv($window->untilUnixNano, $bucketNs) * $bucketNs,
        );
    }
}
