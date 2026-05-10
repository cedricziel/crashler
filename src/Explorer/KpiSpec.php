<?php

declare(strict_types=1);

namespace App\Explorer;

/**
 * Declarative description of a KPI tile.
 *
 * The KpiBundleResolver groups KPI specs by their `groupKey()` so that two
 * KPIs that resolve to the same underlying aggregate query share a single
 * Parquet scan per window.
 */
final readonly class KpiSpec
{
    public function __construct(
        public string $id,
        public string $label,
        public string $function,        // 'count' | 'sum' | 'avg' | 'min' | 'max'
        public ?string $column = null,  // required for non-count
        public ?string $unit = null,    // 'ms', '%', '/min', etc. — purely cosmetic
        public bool $errorIsBad = false, // tints the delta arrow red on increase
    ) {
    }

    /**
     * Two KPIs sharing this key resolve to the same aggregate query and
     * can be batched into a single Parquet scan per window.
     */
    public function groupKey(): string
    {
        return \sprintf('%s:%s', $this->function, $this->column ?? '');
    }
}
