<?php

declare(strict_types=1);

namespace App\Read\Compute;

use App\Read\Compute\Combinators\AllOf;
use App\Read\Compute\Combinators\AnyOf;
use App\Read\Compute\Combinators\Negation;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\ColumnLikePrefix;
use App\Read\Compute\Predicates\ColumnLikeSuffix;
use App\Read\Compute\Predicates\ColumnLowerEqual;
use App\Read\Compute\Predicates\JsonAttributeEquals;
use App\Read\Compute\Predicates\JsonStringContains;
use App\Read\Compute\Predicates\Predicate;

/**
 * Compiles the POST-search predicate-tree DSL (a JSON tree shape) into the
 * runtime predicate classes that {@see ParquetScanner} consumes.
 *
 * Per-signal allowed columns and the body-leaf permission are passed in by
 * the calling processor; the compiler itself is signal-agnostic.
 *
 * Caps enforced:
 *   - Tree depth ≤ {@see DEFAULT_MAX_DEPTH} (8)
 *   - Single `in` list length ≤ {@see DEFAULT_MAX_IN_LIST} (256)
 *   - Distinct attribute leaves ≤ supplied $maxAttributeFilters
 *
 * Validation errors raise {@see InvalidPredicateTreeException} which the
 * processor surfaces as HTTP 400.
 */
final readonly class PredicateTreeCompiler
{
    public const int DEFAULT_MAX_DEPTH = 8;
    public const int DEFAULT_MAX_IN_LIST = 256;

    /**
     * @param list<string>          $allowedColumns snake_case Parquet column names the signal accepts in `column` leaves
     * @param array<string, string> $aliases        optional camelCase → snake_case lookup so the DSL accepts either form
     */
    public function __construct(
        public array $allowedColumns,
        public bool $allowsBodyLeaf,
        public int $maxAttributeFilters,
        public string $bodyColumn = 'body_json',
        public string $attributesColumn = 'attributes_json',
        public array $aliases = [],
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxInListSize = self::DEFAULT_MAX_IN_LIST,
    ) {
    }

    /**
     * @param array<string, mixed> $tree the parsed `criteria` JSON
     *
     * @return list<Predicate> top-level conjuncts; the scanner ANDs them
     */
    public function compile(array $tree): array
    {
        if ([] === $tree) {
            throw new InvalidPredicateTreeException('`criteria` must be a non-empty predicate tree.');
        }

        $attributeKeys = [];
        $compiled = $this->compileNode($tree, depth: 0, attributeKeys: $attributeKeys);

        // Top-level AllOf is unwrapped so the scanner sees a flat list it
        // can sort by tier directly.
        if ($compiled instanceof AllOf) {
            return $compiled->children;
        }

        return [$compiled];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $attributeKeys distinct attribute keys seen so far (mutated)
     */
    private function compileNode(array $node, int $depth, array &$attributeKeys): Predicate
    {
        if ($depth > $this->maxDepth) {
            throw new InvalidPredicateTreeException(\sprintf(
                'Predicate tree depth exceeds the cap of %d.',
                $this->maxDepth,
            ));
        }

        $kindKeys = ['all', 'any', 'not', 'column', 'attribute', 'body'];
        $present = [];
        foreach ($kindKeys as $key) {
            if (\array_key_exists($key, $node)) {
                $present[] = $key;
            }
        }
        if (1 !== \count($present)) {
            throw new InvalidPredicateTreeException(\sprintf(
                'Predicate node must declare exactly one of {all, any, not, column, attribute, body}; got %s.',
                [] === $present ? 'none' : '['.implode(', ', $present).']',
            ));
        }

        return match ($present[0]) {
            'all' => $this->compileAll($node, $depth, $attributeKeys),
            'any' => $this->compileAny($node, $depth, $attributeKeys),
            'not' => $this->compileNot($node, $depth, $attributeKeys),
            'column' => $this->compileColumnLeaf($node),
            'attribute' => $this->compileAttributeLeaf($node, $attributeKeys),
            'body' => $this->compileBodyLeaf($node),
        };
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $attributeKeys
     */
    private function compileAll(array $node, int $depth, array &$attributeKeys): Predicate
    {
        $children = $node['all'];
        if (!\is_array($children) || [] === $children || !array_is_list($children)) {
            throw new InvalidPredicateTreeException('`all` must be a non-empty array of predicate nodes.');
        }
        $compiled = [];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                throw new InvalidPredicateTreeException('`all` children must be predicate node objects.');
            }
            $compiled[] = $this->compileNode($child, $depth + 1, $attributeKeys);
        }

        return 1 === \count($compiled) ? $compiled[0] : new AllOf(...$compiled);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $attributeKeys
     */
    private function compileAny(array $node, int $depth, array &$attributeKeys): Predicate
    {
        $children = $node['any'];
        if (!\is_array($children) || [] === $children || !array_is_list($children)) {
            throw new InvalidPredicateTreeException('`any` must be a non-empty array of predicate nodes.');
        }
        $compiled = [];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                throw new InvalidPredicateTreeException('`any` children must be predicate node objects.');
            }
            $compiled[] = $this->compileNode($child, $depth + 1, $attributeKeys);
        }

        return 1 === \count($compiled) ? $compiled[0] : new AnyOf(...$compiled);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $attributeKeys
     */
    private function compileNot(array $node, int $depth, array &$attributeKeys): Predicate
    {
        $child = $node['not'];
        if (!\is_array($child) || [] === $child) {
            throw new InvalidPredicateTreeException('`not` must wrap a single predicate node object.');
        }

        return new Negation($this->compileNode($child, $depth + 1, $attributeKeys));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function compileColumnLeaf(array $node): Predicate
    {
        $rawColumn = $node['column'] ?? null;
        $op = $node['op'] ?? null;
        if (!\is_string($rawColumn) || '' === $rawColumn) {
            throw new InvalidPredicateTreeException('`column` leaf requires a non-empty `column` string.');
        }
        if (!\is_string($op) || '' === $op) {
            throw new InvalidPredicateTreeException(\sprintf('`column` leaf for `%s` requires an `op`.', $rawColumn));
        }
        $column = $this->aliases[$rawColumn] ?? $rawColumn;
        if (!\in_array($column, $this->allowedColumns, true)) {
            throw new InvalidPredicateTreeException(\sprintf(
                'Unknown column `%s` in column leaf. Allowed: [%s].',
                $rawColumn,
                implode(', ', $this->allowedColumns),
            ));
        }
        if (!\array_key_exists('value', $node)) {
            throw new InvalidPredicateTreeException(\sprintf('`column` leaf for `%s` requires a `value`.', $rawColumn));
        }
        $value = $node['value'];

        return match ($op) {
            'eq' => new ColumnEquals($column, $this->scalarString($value, $column)),
            'ne' => new Negation(new ColumnEquals($column, $this->scalarString($value, $column))),
            'gte' => new ColumnGreaterEqual($column, $this->numeric($value, $column, 'gte')),
            'lte' => new ColumnLowerEqual($column, $this->numeric($value, $column, 'lte')),
            'prefix' => new ColumnLikePrefix($column, $this->stringValue($value, $column, 'prefix')),
            'suffix' => new ColumnLikeSuffix($column, $this->stringValue($value, $column, 'suffix')),
            'in' => $this->compileIn($column, $value),
            default => throw new InvalidPredicateTreeException(\sprintf(
                'Unknown operator `%s` on column `%s`. Supported: eq, ne, gte, lte, prefix, suffix, in.',
                $op,
                $rawColumn,
            )),
        };
    }

    private function compileIn(string $column, mixed $value): Predicate
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            throw new InvalidPredicateTreeException(\sprintf(
                '`in` operator on column `%s` requires a non-empty array `value`.',
                $column,
            ));
        }
        if (\count($value) > $this->maxInListSize) {
            throw new InvalidPredicateTreeException(\sprintf(
                '`in` list on column `%s` has %d entries; cap is %d.',
                $column,
                \count($value),
                $this->maxInListSize,
            ));
        }
        $children = [];
        foreach ($value as $entry) {
            $children[] = new ColumnEquals($column, $this->scalarString($entry, $column));
        }

        return 1 === \count($children) ? $children[0] : new AnyOf(...$children);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $attributeKeys
     */
    private function compileAttributeLeaf(array $node, array &$attributeKeys): Predicate
    {
        $key = $node['attribute'] ?? null;
        $op = $node['op'] ?? 'eq';
        if (!\is_string($key) || '' === $key) {
            throw new InvalidPredicateTreeException('`attribute` leaf requires a non-empty `attribute` string.');
        }
        if ('eq' !== $op) {
            throw new InvalidPredicateTreeException(\sprintf(
                'Unsupported operator `%s` on attribute leaf. Only `eq` is supported in v1.',
                \is_string($op) ? $op : '<non-string>',
            ));
        }
        if (!\array_key_exists('value', $node)) {
            throw new InvalidPredicateTreeException(\sprintf('`attribute` leaf for `%s` requires a `value`.', $key));
        }
        $value = $this->scalarString($node['value'], 'attribute.'.$key);

        $attributeKeys[$key] = true;
        if (\count($attributeKeys) > $this->maxAttributeFilters) {
            throw new InvalidPredicateTreeException(\sprintf(
                'Predicate tree carries more than %d distinct `attribute` filters.',
                $this->maxAttributeFilters,
            ));
        }

        return new JsonAttributeEquals($this->attributesColumn, $key, $value);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function compileBodyLeaf(array $node): Predicate
    {
        if (!$this->allowsBodyLeaf) {
            throw new InvalidPredicateTreeException('`body` leaf is logs-only; this signal does not support body filters.');
        }
        $op = $node['body'] ?? null;
        if ('contains' !== $op) {
            throw new InvalidPredicateTreeException(\sprintf(
                'Unsupported `body` operator `%s`. Only `contains` is supported.',
                \is_string($op) ? $op : '<non-string>',
            ));
        }
        if (!\array_key_exists('value', $node)) {
            throw new InvalidPredicateTreeException('`body` leaf requires a `value`.');
        }
        $needle = $this->stringValue($node['value'], 'body', 'contains');

        return new JsonStringContains($this->bodyColumn, $needle);
    }

    private function scalarString(mixed $value, string $context): string|int|bool
    {
        if (\is_string($value) || \is_int($value) || \is_bool($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return (string) $value;
        }

        throw new InvalidPredicateTreeException(\sprintf(
            'Value for `%s` must be a string, integer, float, or boolean; got %s.',
            $context,
            get_debug_type($value),
        ));
    }

    private function numeric(mixed $value, string $column, string $op): int|float
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }

        throw new InvalidPredicateTreeException(\sprintf(
            'Operator `%s` on column `%s` requires a numeric value; got %s.',
            $op,
            $column,
            get_debug_type($value),
        ));
    }

    private function stringValue(mixed $value, string $context, string $op): string
    {
        if (\is_string($value)) {
            return $value;
        }

        throw new InvalidPredicateTreeException(\sprintf(
            'Operator `%s` on `%s` requires a string value; got %s.',
            $op,
            $context,
            get_debug_type($value),
        ));
    }
}
