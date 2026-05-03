<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\SpanEventDto;
use App\Otlp\SpanEventJsonEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanEventJsonEncoder::class)]
final class SpanEventJsonEncoderTest extends TestCase
{
    public function testEmptyListEncodesToJsonEmptyArray(): void
    {
        self::assertSame('[]', SpanEventJsonEncoder::encode([]));
    }

    public function testEventEncodesToOtlpHttpJsonShape(): void
    {
        $event = new SpanEventDto(
            timeUnixNano: 1714752000000000010,
            name: 'cache.miss',
            attributes: [
                new KeyValueDto('cache.key', AnyValueDto::string('user:42')),
            ],
            droppedAttributesCount: 0,
        );

        $json = SpanEventJsonEncoder::encode([$event]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded);
        self::assertSame('cache.miss', $decoded[0]['name']);
        // int64 -> numeric string per OTLP/HTTP-JSON
        self::assertSame('1714752000000000010', $decoded[0]['timeUnixNano']);
        self::assertSame([
            ['key' => 'cache.key', 'value' => ['stringValue' => 'user:42']],
        ], $decoded[0]['attributes']);
    }

    public function testEventWithoutAttributesOmitsAttributesKeyOrEmits(): void
    {
        $event = new SpanEventDto(
            timeUnixNano: 1,
            name: 'flush',
            attributes: [],
            droppedAttributesCount: 0,
        );

        $json = SpanEventJsonEncoder::encode([$event]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('flush', $decoded[0]['name']);
        self::assertSame('1', $decoded[0]['timeUnixNano']);
        // Empty attributes is allowed to be present-as-[] or omitted; we standardise on present
        self::assertSame([], $decoded[0]['attributes'] ?? []);
    }

    public function testDroppedAttributesCountEmittedWhenNonZero(): void
    {
        $event = new SpanEventDto(
            timeUnixNano: 1,
            name: 'flush',
            attributes: [],
            droppedAttributesCount: 3,
        );

        $json = SpanEventJsonEncoder::encode([$event]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(3, $decoded[0]['droppedAttributesCount']);
    }

    public function testDroppedAttributesCountOmittedWhenZero(): void
    {
        $event = new SpanEventDto(
            timeUnixNano: 1,
            name: 'flush',
            attributes: [],
            droppedAttributesCount: 0,
        );

        $json = SpanEventJsonEncoder::encode([$event]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('droppedAttributesCount', $decoded[0]);
    }

    public function testAnyValueVariantsPreservedInsideEventAttributes(): void
    {
        $event = new SpanEventDto(
            timeUnixNano: 5,
            name: 'mixed',
            attributes: [
                new KeyValueDto('s', AnyValueDto::string('x')),
                new KeyValueDto('i', AnyValueDto::int(42)),
                new KeyValueDto('d', AnyValueDto::double(1.5)),
                new KeyValueDto('b', AnyValueDto::bool(true)),
            ],
            droppedAttributesCount: 0,
        );

        $json = SpanEventJsonEncoder::encode([$event]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['stringValue' => 'x'], $decoded[0]['attributes'][0]['value']);
        self::assertSame(['intValue' => '42'], $decoded[0]['attributes'][1]['value']);
        self::assertSame(['doubleValue' => 1.5], $decoded[0]['attributes'][2]['value']);
        self::assertSame(['boolValue' => true], $decoded[0]['attributes'][3]['value']);
    }
}
