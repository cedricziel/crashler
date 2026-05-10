<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Criteria\TimeWindow;

/**
 * Given a current `TimeWindow`, computes the immediately-prior window of
 * the same duration. Used to render delta-vs-prior-period values in the
 * KPI strip.
 */
final readonly class PriorWindowCalculator
{
    public function priorOf(TimeWindow $current): TimeWindow
    {
        $duration = $current->untilUnixNano - $current->sinceUnixNano;

        return new TimeWindow(
            sinceUnixNano: $current->sinceUnixNano - $duration,
            untilUnixNano: $current->sinceUnixNano,
        );
    }
}
