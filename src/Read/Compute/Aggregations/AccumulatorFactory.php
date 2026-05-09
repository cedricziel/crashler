<?php

declare(strict_types=1);

namespace App\Read\Compute\Aggregations;

/**
 * Maps a `function` parameter value to a fresh per-group accumulator.
 *
 * v1 supports count, sum, avg, min, max. Percentiles (p50/p90/p95/p99) are
 * tracked as a follow-up — the accumulator interface accommodates them
 * without API changes.
 */
final class AccumulatorFactory
{
    public const array SUPPORTED_FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    public static function for(string $function): Accumulator
    {
        return match ($function) {
            'count' => new CountAccumulator(),
            'sum' => new SumAccumulator(),
            'avg' => new AvgAccumulator(),
            'min' => new MinAccumulator(),
            'max' => new MaxAccumulator(),
            default => throw new \InvalidArgumentException(\sprintf(
                'Unsupported aggregation function `%s`. Supported: %s.',
                $function,
                implode(', ', self::SUPPORTED_FUNCTIONS),
            )),
        };
    }

    public static function functionRequiresColumn(string $function): bool
    {
        return 'count' !== $function;
    }
}
