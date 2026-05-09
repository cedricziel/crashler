<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

final readonly class ColumnLowerEqual implements Predicate
{
    public function __construct(
        public string $column,
        public int|float $value,
    ) {
    }

    public function evaluate(array $row): bool
    {
        $cellValue = $row[$this->column] ?? null;
        if (null === $cellValue) {
            return false;
        }

        return $cellValue <= $this->value;
    }

    public function tier(): int
    {
        return 2;
    }
}
