<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp\Dto;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\KeyValueDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnyValueDto::class)]
final class AnyValueDtoTest extends TestCase
{
    public function testStringVariant(): void
    {
        $value = AnyValueDto::string('hello');

        self::assertSame('hello', $value->stringValue);
        self::assertNull($value->intValue);
        self::assertNull($value->doubleValue);
        self::assertNull($value->boolValue);
        self::assertNull($value->bytesValue);
        self::assertNull($value->arrayValue);
        self::assertNull($value->kvlistValue);
    }

    public function testIntVariant(): void
    {
        $value = AnyValueDto::int(42);

        self::assertSame(42, $value->intValue);
        self::assertNull($value->stringValue);
    }

    public function testDoubleVariant(): void
    {
        $value = AnyValueDto::double(3.14);

        self::assertSame(3.14, $value->doubleValue);
    }

    public function testBoolVariant(): void
    {
        $value = AnyValueDto::bool(true);

        self::assertTrue($value->boolValue);
    }

    public function testBytesVariant(): void
    {
        $value = AnyValueDto::bytes("\x00\x01\x02");

        self::assertSame("\x00\x01\x02", $value->bytesValue);
    }

    public function testArrayVariant(): void
    {
        $inner = [AnyValueDto::int(1), AnyValueDto::int(2)];
        $value = AnyValueDto::array($inner);

        self::assertSame($inner, $value->arrayValue);
    }

    public function testKvlistVariant(): void
    {
        $kv = [new KeyValueDto('k', AnyValueDto::string('v'))];
        $value = AnyValueDto::kvlist($kv);

        self::assertSame($kv, $value->kvlistValue);
    }
}
