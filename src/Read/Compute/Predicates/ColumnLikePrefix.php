<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

final readonly class ColumnLikePrefix implements Predicate
{
    public function __construct(
        public string $column,
        public string $prefix,
    ) {
    }

    public function evaluate(array $row): bool
    {
        $cellValue = $row[$this->column] ?? null;
        if (!\is_string($cellValue)) {
            return false;
        }

        return str_starts_with($cellValue, $this->prefix);
    }

    public function tier(): int
    {
        return 3;
    }
}
