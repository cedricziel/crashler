<?php

declare(strict_types=1);

namespace App\Read\Compute\Aggregations;

/**
 * Per-group aggregation state. The {@see AggregatingScanner} feeds rows
 * one-at-a-time and reads the rolled-up `value()` at the end of the scan.
 *
 * Implementations track the partial state needed for their function; the
 * scanner has no knowledge of the function's mathematics.
 */
interface Accumulator
{
    /**
     * Feed a single value into the running aggregation.
     *
     * `null` is allowed and is generally treated as "skip this row" — most
     * accumulators ignore null. Count is the exception: it counts presence,
     * so it counts non-null values OR rows depending on how it's wired.
     */
    public function feed(int|float|null $value): void;

    /**
     * Final aggregation value. May be `null` when the accumulator received
     * zero non-null contributions (e.g. avg of nothing).
     */
    public function value(): int|float|null;

    /**
     * Number of contributions that successfully fed the accumulator.
     */
    public function sampleCount(): int;
}
