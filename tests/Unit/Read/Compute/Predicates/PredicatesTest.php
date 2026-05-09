<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Compute\Predicates;

use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Compute\Predicates\ColumnLikePrefix;
use App\Read\Compute\Predicates\ColumnLikeSuffix;
use App\Read\Compute\Predicates\JsonAttributeEquals;
use App\Read\Compute\Predicates\JsonStringContains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColumnEquals::class)]
#[CoversClass(ColumnGreaterEqual::class)]
#[CoversClass(ColumnInRange::class)]
#[CoversClass(ColumnLikePrefix::class)]
#[CoversClass(ColumnLikeSuffix::class)]
#[CoversClass(JsonStringContains::class)]
#[CoversClass(JsonAttributeEquals::class)]
final class PredicatesTest extends TestCase
{
    public function testColumnEqualsString(): void
    {
        $p = new ColumnEquals('name', 'foo');
        self::assertTrue($p->evaluate(['name' => 'foo']));
        self::assertFalse($p->evaluate(['name' => 'bar']));
        self::assertFalse($p->evaluate(['name' => null]));
        self::assertFalse($p->evaluate([])); // missing column
        self::assertSame(2, $p->tier());
    }

    public function testColumnEqualsInt(): void
    {
        $p = new ColumnEquals('severity_number', 17);
        self::assertTrue($p->evaluate(['severity_number' => 17]));
        self::assertFalse($p->evaluate(['severity_number' => 9]));
    }

    public function testColumnGreaterEqual(): void
    {
        $p = new ColumnGreaterEqual('severity_number', 17);
        self::assertTrue($p->evaluate(['severity_number' => 17]));
        self::assertTrue($p->evaluate(['severity_number' => 24]));
        self::assertFalse($p->evaluate(['severity_number' => 9]));
        self::assertFalse($p->evaluate(['severity_number' => null])); // null rejected
        self::assertFalse($p->evaluate([])); // missing column rejected
        self::assertSame(2, $p->tier());
    }

    public function testColumnInRangeInclusive(): void
    {
        $p = new ColumnInRange('time_unix_nano', 100, 200);
        self::assertTrue($p->evaluate(['time_unix_nano' => 100])); // lower bound inclusive
        self::assertTrue($p->evaluate(['time_unix_nano' => 200])); // upper bound inclusive
        self::assertTrue($p->evaluate(['time_unix_nano' => 150]));
        self::assertFalse($p->evaluate(['time_unix_nano' => 99]));
        self::assertFalse($p->evaluate(['time_unix_nano' => 201]));
        self::assertFalse($p->evaluate(['time_unix_nano' => null]));
    }

    public function testColumnLikePrefix(): void
    {
        $p = new ColumnLikePrefix('name', 'GET ');
        self::assertTrue($p->evaluate(['name' => 'GET /orders/123']));
        self::assertFalse($p->evaluate(['name' => 'POST /orders']));
        self::assertFalse($p->evaluate(['name' => null]));
        self::assertSame(3, $p->tier());
    }

    public function testColumnLikeSuffix(): void
    {
        $p = new ColumnLikeSuffix('name', '.duration');
        self::assertTrue($p->evaluate(['name' => 'http.server.request.duration']));
        self::assertFalse($p->evaluate(['name' => 'http.server.request.size']));
    }

    public function testJsonStringContains(): void
    {
        $p = new JsonStringContains('body_json', 'connection refused');
        self::assertTrue($p->evaluate(['body_json' => '{"stringValue":"connection refused"}']));
        self::assertTrue($p->evaluate(['body_json' => '{"stringValue":"db connection refused after retry"}']));
        self::assertFalse($p->evaluate(['body_json' => '{"stringValue":"all good"}']));
        self::assertFalse($p->evaluate(['body_json' => null]));
        self::assertSame(3, $p->tier());
    }

    public function testJsonAttributeEqualsAttributeShape(): void
    {
        $p = new JsonAttributeEquals('attributes_json', 'exception.type', 'RuntimeException');

        $attrs = json_encode([
            ['key' => 'exception.type', 'value' => ['stringValue' => 'RuntimeException']],
            ['key' => 'http.method', 'value' => ['stringValue' => 'GET']],
        ]);
        self::assertTrue($p->evaluate(['attributes_json' => $attrs]));

        $other = json_encode([
            ['key' => 'http.method', 'value' => ['stringValue' => 'GET']],
        ]);
        self::assertFalse($p->evaluate(['attributes_json' => $other]));

        self::assertSame(4, $p->tier());
    }

    public function testJsonAttributeEqualsDefendsAgainstSubstringFalsePositives(): void
    {
        // Naive substring matching would falsely match this row because
        // "exception.type" appears in the value. The decoded walk must
        // require the structural shape (a top-level `key` field equal to
        // the looked-for key).
        $p = new JsonAttributeEquals('attributes_json', 'exception.type', 'RuntimeException');

        $sneaky = json_encode([
            ['key' => 'log.message', 'value' => ['stringValue' => 'caught exception.type=RuntimeException via wrapper']],
        ]);
        self::assertFalse($p->evaluate(['attributes_json' => $sneaky]), 'must NOT match — substring inside another value is not a structural match');
    }

    public function testJsonAttributeEqualsIntValueVariant(): void
    {
        $p = new JsonAttributeEquals('attributes_json', 'http.status_code', '500');

        $attrs = json_encode([
            ['key' => 'http.status_code', 'value' => ['intValue' => '500']],
        ]);
        self::assertTrue($p->evaluate(['attributes_json' => $attrs]));
    }

    public function testJsonAttributeEqualsBoolValueVariant(): void
    {
        $p = new JsonAttributeEquals('attributes_json', 'feature.enabled', 'true');

        $attrs = json_encode([
            ['key' => 'feature.enabled', 'value' => ['boolValue' => true]],
        ]);
        self::assertTrue($p->evaluate(['attributes_json' => $attrs]));
    }

    public function testJsonAttributeEqualsExemplarShape(): void
    {
        // Exemplars use a different shape — top-level `traceId` field —
        // not the OTLP key/value attribute shape. The predicate handles both.
        $p = new JsonAttributeEquals('exemplars_json', 'traceId', '5b8aa5a2d2c872e8321cf37308d69df2');

        $exemplars = json_encode([
            [
                'traceId' => '5b8aa5a2d2c872e8321cf37308d69df2',
                'spanId' => '051581bf3cb55c13',
                'asDouble' => 1.5,
            ],
        ]);
        self::assertTrue($p->evaluate(['exemplars_json' => $exemplars]));

        $other = json_encode([
            ['traceId' => 'aaaa', 'spanId' => 'bbbb'],
        ]);
        self::assertFalse($p->evaluate(['exemplars_json' => $other]));
    }

    public function testJsonAttributeEqualsExemplarSubstringFalsePositiveBlocked(): void
    {
        // The hex appears inside another field's value — must not match.
        $p = new JsonAttributeEquals('exemplars_json', 'traceId', '5b8aa5a2d2c872e8321cf37308d69df2');

        $sneaky = json_encode([
            [
                'traceId' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'note' => 'related to 5b8aa5a2d2c872e8321cf37308d69df2',
            ],
        ]);
        self::assertFalse($p->evaluate(['exemplars_json' => $sneaky]));
    }

    public function testJsonAttributeEqualsEmptyArray(): void
    {
        $p = new JsonAttributeEquals('attributes_json', 'foo', 'bar');
        self::assertFalse($p->evaluate(['attributes_json' => '[]']));
    }

    public function testJsonAttributeEqualsMalformedJsonReturnsFalse(): void
    {
        $p = new JsonAttributeEquals('attributes_json', 'foo', 'bar');

        // Defensive: scanner shouldn't crash on a corrupt row — the predicate
        // simply fails and the scanner moves on.
        self::assertFalse($p->evaluate(['attributes_json' => '{not valid json']));
        self::assertFalse($p->evaluate(['attributes_json' => '']));
        self::assertFalse($p->evaluate(['attributes_json' => null]));
    }
}
