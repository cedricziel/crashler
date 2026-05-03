<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\SpanLinkDto;
use App\Otlp\SpanLinkJsonEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanLinkJsonEncoder::class)]
final class SpanLinkJsonEncoderTest extends TestCase
{
    public function testEmptyListEncodesToJsonEmptyArray(): void
    {
        self::assertSame('[]', SpanLinkJsonEncoder::encode([]));
    }

    public function testLinkEncodesWithHexIdsAndAttributes(): void
    {
        $traceHex = '5b8aa5a2d2c872e8321cf37308d69df2';
        $spanHex = '051581bf3cb55c13';
        $link = new SpanLinkDto(
            traceId: (string) hex2bin($traceHex),
            spanId: (string) hex2bin($spanHex),
            traceState: 'rojo=00f',
            attributes: [
                new KeyValueDto('link.kind', AnyValueDto::string('follows-from')),
            ],
            droppedAttributesCount: 0,
            flags: 1,
        );

        $json = SpanLinkJsonEncoder::encode([$link]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded);
        self::assertSame($traceHex, $decoded[0]['traceId']);
        self::assertSame($spanHex, $decoded[0]['spanId']);
        self::assertSame('rojo=00f', $decoded[0]['traceState']);
        self::assertSame(1, $decoded[0]['flags']);
        self::assertSame([
            ['key' => 'link.kind', 'value' => ['stringValue' => 'follows-from']],
        ], $decoded[0]['attributes']);
    }

    public function testNullTraceStateAndFlagsOmitted(): void
    {
        $link = new SpanLinkDto(
            traceId: (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2'),
            spanId: (string) hex2bin('051581bf3cb55c13'),
            traceState: null,
            attributes: [],
            droppedAttributesCount: 0,
            flags: null,
        );

        $json = SpanLinkJsonEncoder::encode([$link]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('traceState', $decoded[0]);
        self::assertArrayNotHasKey('flags', $decoded[0]);
    }

    public function testDroppedAttributesCountOmittedWhenZero(): void
    {
        $link = new SpanLinkDto(
            traceId: (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2'),
            spanId: (string) hex2bin('051581bf3cb55c13'),
            traceState: null,
            attributes: [],
            droppedAttributesCount: 0,
            flags: null,
        );

        $json = SpanLinkJsonEncoder::encode([$link]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('droppedAttributesCount', $decoded[0]);
    }

    public function testDroppedAttributesCountEmittedWhenNonZero(): void
    {
        $link = new SpanLinkDto(
            traceId: (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2'),
            spanId: (string) hex2bin('051581bf3cb55c13'),
            traceState: null,
            attributes: [],
            droppedAttributesCount: 5,
            flags: null,
        );

        $json = SpanLinkJsonEncoder::encode([$link]);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(5, $decoded[0]['droppedAttributesCount']);
    }
}
