<?php

declare(strict_types=1);

namespace App\Read\Compute;

/**
 * Result of an aggregation scan: a list of rows, one per (group, [bucket])
 * combination.
 *
 * Each row is an associative array with keys `group`, `function`, `column`
 * (optional, omitted for `count`), `value`, and `sample_count`.
 */
final readonly class AggregateResult
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(public array $rows)
    {
    }
}
