<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

/**
 * Predicate evaluated against a single row map (column-name → value).
 *
 * Predicates carry a `tier` so the scanner can order evaluation cheap-first
 * — top-level column comparisons before JSON-string scans. See design.md D12
 * for the tier table.
 */
interface Predicate
{
    public function evaluate(array $row): bool;

    /**
     * Lower number = cheaper. The scanner sorts predicates ascending and
     * short-circuits on the first failing one per row.
     */
    public function tier(): int;
}
