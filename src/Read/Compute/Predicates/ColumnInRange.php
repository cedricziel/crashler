<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

final readonly class ColumnInRange implements Predicate
{
    public function __construct(
        public string $column,
        public int|float $low,
        public int|float $high,
    ) {
    }

    public function evaluate(array $row): bool
    {
        $cellValue = $row[$this->column] ?? null;
        if (null === $cellValue) {
            return false;
        }

        return $cellValue >= $this->low && $cellValue <= $this->high;
    }

    public function tier(): int
    {
        return 2;
    }
}
