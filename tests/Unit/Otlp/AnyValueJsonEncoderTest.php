<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\AnyValueJsonEncoder;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\KeyValueDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnyValueJsonEncoder::class)]
final class AnyValueJsonEncoderTest extends TestCase
{
    public function testStringVariantEncoded(): void
    {
        self::assertSame('{"stringValue":"hello"}', AnyValueJsonEncoder::encode(AnyValueDto::string('hello')));
    }

    public function testIntVariantEncodedAsNumericString(): void
    {
        // Per OTLP/HTTP-JSON spec, int64 fields are encoded as numeric strings.
        self::assertSame('{"intValue":"42"}', AnyValueJsonEncoder::encode(AnyValueDto::int(42)));
    }

    public function testDoubleVariantEncodedAsNumber(): void
    {
        self::assertSame('{"doubleValue":3.14}', AnyValueJsonEncoder::encode(AnyValueDto::double(3.14)));
    }

    public function testBoolVariantEncoded(): void
    {
        self::assertSame('{"boolValue":true}', AnyValueJsonEncoder::encode(AnyValueDto::bool(true)));
    }

    public function testBytesVariantEncodedAsBase64(): void
    {
        self::assertSame(
            '{"bytesValue":"AAEC/w=="}',
            AnyValueJsonEncoder::encode(AnyValueDto::bytes("\x00\x01\x02\xff")),
        );
    }

    public function testArrayVariantRecursivelyEncoded(): void
    {
        $value = AnyValueDto::array([
            AnyValueDto::string('a'),
            AnyValueDto::int(7),
        ]);

        self::assertSame(
            '{"arrayValue":{"values":[{"stringValue":"a"},{"intValue":"7"}]}}',
            AnyValueJsonEncoder::encode($value),
        );
    }

    public function testKvlistVariantRecursivelyEncoded(): void
    {
        $value = AnyValueDto::kvlist([
            new KeyValueDto('k1', AnyValueDto::string('v1')),
            new KeyValueDto('k2', AnyValueDto::int(2)),
        ]);

        self::assertSame(
            '{"kvlistValue":{"values":[{"key":"k1","value":{"stringValue":"v1"}},{"key":"k2","value":{"intValue":"2"}}]}}',
            AnyValueJsonEncoder::encode($value),
        );
    }

    public function testEncodeAttributesProducesArrayOfKeyValueObjects(): void
    {
        $attrs = [
            new KeyValueDto('http.status_code', AnyValueDto::int(500)),
            new KeyValueDto('user.id', AnyValueDto::string('u-42')),
        ];

        self::assertSame(
            '[{"key":"http.status_code","value":{"intValue":"500"}},{"key":"user.id","value":{"stringValue":"u-42"}}]',
            AnyValueJsonEncoder::encodeAttributes($attrs),
        );
    }

    public function testEncodeAttributesEmptyList(): void
    {
        self::assertSame('[]', AnyValueJsonEncoder::encodeAttributes([]));
    }

    public function testEmptyAnyValueEncodedAsEmptyObject(): void
    {
        self::assertSame('{}', AnyValueJsonEncoder::encode(new AnyValueDto()));
    }
}
