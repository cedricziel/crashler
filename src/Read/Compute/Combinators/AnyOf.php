<?php

declare(strict_types=1);

namespace App\Read\Compute\Combinators;

use App\Read\Compute\Predicates\Predicate;

/**
 * Logical OR over a list of child predicates.
 *
 * Used by the POST search predicate-tree DSL's `any` combinator and as the
 * compiled form of an `in` leaf (a chain of `ColumnEquals` ORed together).
 *
 * Tier is `max(child.tier)` — OR cannot short-circuit until the cheapest
 * child has been evaluated against rows that could match, so the conservative
 * tier is the most expensive child's tier.
 */
final readonly class AnyOf implements Predicate
{
    /** @var list<Predicate> */
    public array $children;

    public function __construct(Predicate ...$children)
    {
        if ([] === $children) {
            throw new \InvalidArgumentException('AnyOf requires at least one child predicate.');
        }
        $this->children = array_values($children);
    }

    public function evaluate(array $row): bool
    {
        foreach ($this->children as $child) {
            if ($child->evaluate($row)) {
                return true;
            }
        }

        return false;
    }

    public function tier(): int
    {
        $maxTier = 0;
        foreach ($this->children as $child) {
            $maxTier = max($maxTier, $child->tier());
        }

        return $maxTier;
    }
}
