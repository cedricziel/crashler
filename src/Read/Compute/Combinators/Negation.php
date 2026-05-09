<?php

declare(strict_types=1);

namespace App\Read\Compute\Combinators;

use App\Read\Compute\Predicates\Predicate;

/**
 * Logical NOT around a single child predicate.
 *
 * Used by the POST search predicate-tree DSL's `not` combinator and as the
 * compiled form of a `ne` operator (`Negation(ColumnEquals(...))`).
 *
 * Tier is the child's tier — negation does no extra work, just inverts.
 */
final readonly class Negation implements Predicate
{
    public function __construct(public Predicate $child)
    {
    }

    public function evaluate(array $row): bool
    {
        return !$this->child->evaluate($row);
    }

    public function tier(): int
    {
        return $this->child->tier();
    }
}
