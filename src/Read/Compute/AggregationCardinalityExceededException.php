<?php

declare(strict_types=1);

namespace App\Read\Compute;

/**
 * Thrown by {@see AggregatingScanner} when distinct group keys exceed
 * `crashler.read.aggregate.max_groups`. The processor maps this to HTTP 400
 * with a "tighten your filters / reduce the group-by" message.
 */
final class AggregationCardinalityExceededException extends \RuntimeException
{
}
