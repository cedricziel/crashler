<?php

declare(strict_types=1);

namespace App\Read\Compute\Predicates;

/**
 * Substring match against a JSON-string column. Used for `bodyContains` on
 * the logs `body_json` column where the JSON wraps a single AnyValue
 * (`{"stringValue":"..."}`) and substring matching gives the right semantic.
 *
 * NOT used for attribute / exemplar filters — those need structural matching
 * (see {@see JsonAttributeEquals}) to defend against false positives.
 */
final readonly class JsonStringContains implements Predicate
{
    public function __construct(
        public string $column,
        public string $needle,
    ) {
    }

    public function evaluate(array $row): bool
    {
        $cellValue = $row[$this->column] ?? null;
        if (!\is_string($cellValue)) {
            return false;
        }

        return str_contains($cellValue, $this->needle);
    }

    public function tier(): int
    {
        return 3;
    }
}
