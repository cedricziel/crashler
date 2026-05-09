<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

final readonly class ColumnLikeSuffix implements Predicate
{
    public function __construct(
        public string $column,
        public string $suffix,
    ) {
    }

    public function evaluate(array $row): bool
    {
        $cellValue = $row[$this->column] ?? null;
        if (!\is_string($cellValue)) {
            return false;
        }

        return str_ends_with($cellValue, $this->suffix);
    }

    public function tier(): int
    {
        return 3;
    }
}
