<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

final readonly class ColumnEquals implements Predicate
{
    public function __construct(
        public string $column,
        public string|int|bool $value,
    ) {
    }

    public function evaluate(array $row): bool
    {
        return ($row[$this->column] ?? null) === $this->value;
    }

    public function tier(): int
    {
        return 2;
    }
}
