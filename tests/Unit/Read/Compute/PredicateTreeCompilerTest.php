<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Compute;

use App\Read\Compute\Combinators\AnyOf;
use App\Read\Compute\Combinators\Negation;
use App\Read\Compute\InvalidPredicateTreeException;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\ColumnLikePrefix;
use App\Read\Compute\Predicates\ColumnLikeSuffix;
use App\Read\Compute\Predicates\ColumnLowerEqual;
use App\Read\Compute\Predicates\JsonAttributeEquals;
use App\Read\Compute\Predicates\JsonStringContains;
use App\Read\Compute\PredicateTreeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PredicateTreeCompiler::class)]
#[CoversClass(InvalidPredicateTreeException::class)]
#[CoversClass(AnyOf::class)]
#[CoversClass(Negation::class)]
#[CoversClass(ColumnLowerEqual::class)]
final class PredicateTreeCompilerTest extends TestCase
{
    private function logsCompiler(int $maxAttrs = 5): PredicateTreeCompiler
    {
        return new PredicateTreeCompiler(
            allowedColumns: ['resource_service_name', 'severity_number', 'trace_id_hex', 'event_name', 'time_unix_nano'],
            allowsBodyLeaf: true,
            maxAttributeFilters: $maxAttrs,
            aliases: ['service' => 'resource_service_name'],
        );
    }

    public function testColumnEqLeaf(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'column' => 'resource_service_name', 'op' => 'eq', 'value' => 'checkout',
        ]);

        self::assertCount(1, $predicates);
        self::assertInstanceOf(ColumnEquals::class, $predicates[0]);
        self::assertSame('resource_service_name', $predicates[0]->column);
        self::assertSame('checkout', $predicates[0]->value);
    }

    public function testColumnAliasResolves(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'column' => 'service', 'op' => 'eq', 'value' => 'payments',
        ]);

        self::assertSame('resource_service_name', $predicates[0]->column);
    }

    public function testColumnGteEmitsGreaterEqual(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'column' => 'severity_number', 'op' => 'gte', 'value' => 17,
        ]);

        self::assertInstanceOf(ColumnGreaterEqual::class, $predicates[0]);
        self::assertSame(17, $predicates[0]->value);
    }

    public function testColumnLteEmitsLowerEqual(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'column' => 'severity_number', 'op' => 'lte', 'value' => 9,
        ]);

        self::assertInstanceOf(ColumnLowerEqual::class, $predicates[0]);
    }

    public function testColumnNeEmitsNotEquals(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'column' => 'resource_service_name', 'op' => 'ne', 'value' => 'internal',
        ]);

        self::assertInstanceOf(Negation::class, $predicates[0]);
        self::assertInstanceOf(ColumnEquals::class, $predicates[0]->child);
    }

    public function testColumnPrefixSuffixLeaves(): void
    {
        $prefix = $this->logsCompiler()->compile([
            'column' => 'event_name', 'op' => 'prefix', 'value' => 'auth.',
        ]);
        self::assertInstanceOf(ColumnLikePrefix::class, $prefix[0]);

        $suffix = $this->logsCompiler()->compile([
            'column' => 'event_name', 'op' => 'suffix', 'value' => '.fail',
        ]);
        self::assertInstanceOf(ColumnLikeSuffix::class, $suffix[0]);
    }

    public function testColumnInCompilesToAnyOfEquals(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'column' => 'trace_id_hex', 'op' => 'in', 'value' => ['aaa', 'bbb', 'ccc'],
        ]);

        self::assertInstanceOf(AnyOf::class, $predicates[0]);
        self::assertCount(3, $predicates[0]->children);
        foreach ($predicates[0]->children as $child) {
            self::assertInstanceOf(ColumnEquals::class, $child);
            self::assertSame('trace_id_hex', $child->column);
        }
    }

    public function testColumnInWithSingleValueUnwrapsToBareEquals(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'column' => 'trace_id_hex', 'op' => 'in', 'value' => ['only-id'],
        ]);

        self::assertInstanceOf(ColumnEquals::class, $predicates[0]);
    }

    public function testAttributeLeaf(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'attribute' => 'exception.type', 'op' => 'eq', 'value' => 'RuntimeException',
        ]);

        self::assertInstanceOf(JsonAttributeEquals::class, $predicates[0]);
        self::assertSame('attributes_json', $predicates[0]->column);
        self::assertSame('exception.type', $predicates[0]->attrKey);
        self::assertSame('RuntimeException', $predicates[0]->attrValue);
    }

    public function testBodyContainsLeaf(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'body' => 'contains', 'value' => 'panic',
        ]);

        self::assertInstanceOf(JsonStringContains::class, $predicates[0]);
        self::assertSame('body_json', $predicates[0]->column);
        self::assertSame('panic', $predicates[0]->needle);
    }

    public function testBodyLeafRejectedWhenSignalDoesNotAllow(): void
    {
        $compiler = new PredicateTreeCompiler(
            allowedColumns: ['name'],
            allowsBodyLeaf: false,
            maxAttributeFilters: 5,
        );

        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/logs-only/i');

        $compiler->compile(['body' => 'contains', 'value' => 'panic']);
    }

    public function testAllCombinatorReturnsFlatTopLevel(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'all' => [
                ['column' => 'service', 'op' => 'eq', 'value' => 'checkout'],
                ['column' => 'severity_number', 'op' => 'gte', 'value' => 17],
            ],
        ]);

        // Top-level AllOf is flattened so the scanner sees a flat list.
        self::assertCount(2, $predicates);
        self::assertInstanceOf(ColumnEquals::class, $predicates[0]);
        self::assertInstanceOf(ColumnGreaterEqual::class, $predicates[1]);
    }

    public function testAnyCombinator(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'any' => [
                ['column' => 'service', 'op' => 'eq', 'value' => 'checkout'],
                ['column' => 'service', 'op' => 'eq', 'value' => 'payments'],
            ],
        ]);

        self::assertInstanceOf(AnyOf::class, $predicates[0]);
        self::assertCount(2, $predicates[0]->children);
    }

    public function testNotCombinator(): void
    {
        $predicates = $this->logsCompiler()->compile([
            'not' => ['column' => 'service', 'op' => 'eq', 'value' => 'internal'],
        ]);

        self::assertInstanceOf(Negation::class, $predicates[0]);
        self::assertInstanceOf(ColumnEquals::class, $predicates[0]->child);
    }

    public function testEmptyAllRejected(): void
    {
        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/`all`/');

        $this->logsCompiler()->compile(['all' => []]);
    }

    public function testEmptyAnyRejected(): void
    {
        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/`any`/');

        $this->logsCompiler()->compile(['any' => []]);
    }

    public function testEmptyTreeRejected(): void
    {
        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/non-empty/');

        $this->logsCompiler()->compile([]);
    }

    public function testUnknownColumnRejected(): void
    {
        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/Unknown column/');

        $this->logsCompiler()->compile([
            'column' => 'banana', 'op' => 'eq', 'value' => 'x',
        ]);
    }

    public function testUnknownOpRejected(): void
    {
        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/Unknown operator/');

        $this->logsCompiler()->compile([
            'column' => 'service', 'op' => 'regex', 'value' => '.*',
        ]);
    }

    public function testInListSizeCapEnforced(): void
    {
        $compiler = new PredicateTreeCompiler(
            allowedColumns: ['trace_id_hex'],
            allowsBodyLeaf: false,
            maxAttributeFilters: 5,
            maxInListSize: 3,
        );

        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/cap is 3/');

        $compiler->compile(['column' => 'trace_id_hex', 'op' => 'in', 'value' => ['a', 'b', 'c', 'd']]);
    }

    public function testDepthCapEnforced(): void
    {
        $compiler = new PredicateTreeCompiler(
            allowedColumns: ['service'],
            allowsBodyLeaf: false,
            maxAttributeFilters: 5,
            aliases: ['service' => 'service'],
            maxDepth: 2,
        );

        // Build a 3-deep tree (above the cap of 2).
        $tree = [
            'all' => [
                ['all' => [
                    ['all' => [
                        ['column' => 'service', 'op' => 'eq', 'value' => 'x'],
                    ]],
                ]],
            ],
        ];

        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/depth/');

        $compiler->compile($tree);
    }

    public function testAttributeKeyCapEnforced(): void
    {
        $compiler = $this->logsCompiler(maxAttrs: 2);

        $this->expectException(InvalidPredicateTreeException::class);
        $this->expectExceptionMessageMatches('/distinct `attribute`/');

        $compiler->compile([
            'all' => [
                ['attribute' => 'a', 'op' => 'eq', 'value' => '1'],
                ['attribute' => 'b', 'op' => 'eq', 'value' => '2'],
                ['attribute' => 'c', 'op' => 'eq', 'value' => '3'],
            ],
        ]);
    }

    public function testColumnLeafMustHaveExactlyOneKind(): void
    {
        $this->expectException(InvalidPredicateTreeException::class);

        // Both `column` and `attribute` set is ambiguous.
        $this->logsCompiler()->compile([
            'column' => 'service',
            'attribute' => 'foo',
            'op' => 'eq',
            'value' => 'x',
        ]);
    }
}
