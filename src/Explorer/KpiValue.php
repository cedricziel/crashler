<?php

declare(strict_types=1);

namespace App\Explorer;

/**
 * Resolved value for a single KPI tile in the strip.
 */
final readonly class KpiValue
{
    public function __construct(
        public KpiSpec $spec,
        public int|float|null $value,
        public ?float $deltaPercent,
    ) {
    }

    public static function empty(KpiSpec $spec): self
    {
        return new self($spec, null, null);
    }
}
