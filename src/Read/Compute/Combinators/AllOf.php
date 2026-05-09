<?php

declare(strict_types=1);

namespace App\Read\Compute\Combinators;

use App\Read\Compute\Predicates\Predicate;

/**
 * Logical AND over a list of child predicates.
 *
 * The scanner already AND-composes the top-level predicate list it receives,
 * so this combinator exists for nested ANDs that appear inside `not(all(...))`
 * or `any(all(...), all(...))` shapes from the POST search DSL.
 *
 * Tier is `max(child.tier)` — short-circuiting AND can short-circuit on the
 * cheap child but the conservative tier (used by the scanner's outer
 * ordering) is the most expensive child's tier.
 */
final readonly class AllOf implements Predicate
{
    /** @var list<Predicate> */
    public array $children;

    public function __construct(Predicate ...$children)
    {
        if ([] === $children) {
            throw new \InvalidArgumentException('AllOf requires at least one child predicate.');
        }
        $this->children = array_values($children);
    }

    public function evaluate(array $row): bool
    {
        foreach ($this->children as $child) {
            if (!$child->evaluate($row)) {
                return false;
            }
        }

        return true;
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
