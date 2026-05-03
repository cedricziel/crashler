<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\TracesJsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TracesJsonDecoder::class)]
final class TracesJsonDecoderTest extends TestCase
{
    private TracesJsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new TracesJsonDecoder();
    }

    public function testDecodesMinimalValidRequest(): void
    {
        $traceHex = '5b8aa5a2d2c872e8321cf37308d69df2';
        $spanHex = '051581bf3cb55c13';
        $json = json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => []],
                'scopeSpans' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0'],
                    'spans' => [[
                        'traceId' => $traceHex,
                        'spanId' => $spanHex,
                        'name' => 'GET /orders/:id',
                        'kind' => 2,
                        'startTimeUnixNano' => '1714752000000000000',
                        'endTimeUnixNano' => '1714752000050000000',
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $dto = $this->decoder->decode($json);

        self::assertInstanceOf(ExportTraceServiceRequestDto::class, $dto);
        self::assertCount(1, $dto->resourceSpans);
        $scope = $dto->resourceSpans[0]->scopeSpans[0];
        self::assertSame('app', $scope->scopeName);
        self::assertCount(1, $scope->spans);
        $span = $scope->spans[0];
        self::assertSame(hex2bin($traceHex), $span->traceId);
        self::assertSame(hex2bin($spanHex), $span->spanId);
        self::assertSame('GET /orders/:id', $span->name);
        self::assertSame(2, $span->kind);
        self::assertSame(1714752000000000000, $span->startTimeUnixNano);
        self::assertSame(1714752000050000000, $span->endTimeUnixNano);
    }

    public function testEmptyResourceSpansAccepted(): void
    {
        $dto = $this->decoder->decode('{"resourceSpans":[]}');
        self::assertSame([], $dto->resourceSpans);
    }

    public function testParentSpanIdEmptyOrAbsentBecomesNull(): void
    {
        $jsonAbsent = $this->minimalJsonWithSpanFields([]);
        $jsonEmpty = $this->minimalJsonWithSpanFields(['parentSpanId' => '']);

        self::assertNull($this->decoder->decode($jsonAbsent)->resourceSpans[0]->scopeSpans[0]->spans[0]->parentSpanId);
        self::assertNull($this->decoder->decode($jsonEmpty)->resourceSpans[0]->scopeSpans[0]->spans[0]->parentSpanId);
    }

    public function testParentSpanIdHexDecoded(): void
    {
        $parentHex = 'aabbccddeeff1122';
        $json = $this->minimalJsonWithSpanFields(['parentSpanId' => $parentHex]);

        $span = $this->decoder->decode($json)->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertSame(hex2bin($parentHex), $span->parentSpanId);
    }

    public function testTimestampsAcceptedAsNumberOrString(): void
    {
        $stringForm = $this->decoder->decode($this->minimalJsonWithSpanFields([
            'startTimeUnixNano' => '1234567890123456789',
            'endTimeUnixNano' => '1234567890123456999',
        ]));
        $numberForm = $this->decoder->decode($this->minimalJsonWithSpanFields([
            'startTimeUnixNano' => 1234567890123456789,
            'endTimeUnixNano' => 1234567890123456999,
        ]));

        $a = $stringForm->resourceSpans[0]->scopeSpans[0]->spans[0];
        $b = $numberForm->resourceSpans[0]->scopeSpans[0]->spans[0];
        self::assertSame($a->startTimeUnixNano, $b->startTimeUnixNano);
        self::assertSame($a->endTimeUnixNano, $b->endTimeUnixNano);
    }

    public function testKindDefaultsToZeroWhenAbsent(): void
    {
        $json = $this->minimalJsonWithSpanFields([]);
        unset(json_decode($json, true)['kind']);
        $dto = $this->decoder->decode($this->minimalJsonWithoutKind());

        self::assertSame(0, $dto->resourceSpans[0]->scopeSpans[0]->spans[0]->kind);
    }

    public function testStatusDecoded(): void
    {
        $json = $this->minimalJsonWithSpanFields([
            'status' => ['code' => 2, 'message' => 'connection refused'],
        ]);

        $span = $this->decoder->decode($json)->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertNotNull($span->status);
        self::assertSame(2, $span->status->code);
        self::assertSame('connection refused', $span->status->message);
    }

    public function testStatusAbsentBecomesNull(): void
    {
        $json = $this->minimalJsonWithSpanFields([]);

        $span = $this->decoder->decode($json)->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertNull($span->status);
    }

    public function testEventsDecoded(): void
    {
        $json = $this->minimalJsonWithSpanFields([
            'events' => [
                [
                    'timeUnixNano' => '1714752000000000010',
                    'name' => 'cache.miss',
                    'attributes' => [
                        ['key' => 'cache.key', 'value' => ['stringValue' => 'user:42']],
                    ],
                ],
            ],
        ]);

        $span = $this->decoder->decode($json)->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertCount(1, $span->events);
        self::assertSame('cache.miss', $span->events[0]->name);
        self::assertSame(1714752000000000010, $span->events[0]->timeUnixNano);
        self::assertCount(1, $span->events[0]->attributes);
        self::assertSame('user:42', $span->events[0]->attributes[0]->value->stringValue);
    }

    public function testLinksDecoded(): void
    {
        $linkTrace = '0123456789abcdef0123456789abcdef';
        $linkSpan = 'fedcba9876543210';
        $json = $this->minimalJsonWithSpanFields([
            'links' => [
                [
                    'traceId' => $linkTrace,
                    'spanId' => $linkSpan,
                    'traceState' => 'rojo=00f',
                    'flags' => 1,
                ],
            ],
        ]);

        $link = $this->decoder->decode($json)->resourceSpans[0]->scopeSpans[0]->spans[0]->links[0];

        self::assertSame(hex2bin($linkTrace), $link->traceId);
        self::assertSame(hex2bin($linkSpan), $link->spanId);
        self::assertSame('rojo=00f', $link->traceState);
        self::assertSame(1, $link->flags);
    }

    public function testEmptyEventsAndLinksDefaultToEmptyArrays(): void
    {
        $json = $this->minimalJsonWithSpanFields([]);

        $span = $this->decoder->decode($json)->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertSame([], $span->events);
        self::assertSame([], $span->links);
    }

    public function testScopeSchemaUrlDecoded(): void
    {
        $json = json_encode([
            'resourceSpans' => [[
                'scopeSpans' => [[
                    'scope' => ['name' => 'app'],
                    'schemaUrl' => 'https://opentelemetry.io/schemas/1.30.0',
                    'spans' => [[
                        'traceId' => str_repeat('a', 32),
                        'spanId' => str_repeat('b', 16),
                        'name' => 'op',
                        'startTimeUnixNano' => '1',
                        'endTimeUnixNano' => '2',
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $scope = $this->decoder->decode($json)->resourceSpans[0]->scopeSpans[0];

        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $scope->schemaUrl);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function schemaMismatchProvider(): iterable
    {
        yield 'missing resourceSpans' => ['{}'];
        yield 'resourceSpans not array' => ['{"resourceSpans":"oops"}'];
        yield 'scopeSpans not array' => [json_encode(['resourceSpans' => [['scopeSpans' => 'oops']]], \JSON_THROW_ON_ERROR)];
        yield 'spans not array' => [json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => 'oops']]]]], \JSON_THROW_ON_ERROR)];
        yield 'span missing traceId' => [json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => [['spanId' => str_repeat('b', 16), 'name' => 'op', 'startTimeUnixNano' => '1', 'endTimeUnixNano' => '2']]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'span missing spanId' => [json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => [['traceId' => str_repeat('a', 32), 'name' => 'op', 'startTimeUnixNano' => '1', 'endTimeUnixNano' => '2']]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'span missing name' => [json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => [['traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16), 'startTimeUnixNano' => '1', 'endTimeUnixNano' => '2']]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'span missing startTimeUnixNano' => [json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => [['traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16), 'name' => 'op', 'endTimeUnixNano' => '2']]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'traceId wrong length' => [json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => [['traceId' => 'cafe', 'spanId' => str_repeat('b', 16), 'name' => 'op', 'startTimeUnixNano' => '1', 'endTimeUnixNano' => '2']]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'spanId non-hex' => [json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => [['traceId' => str_repeat('a', 32), 'spanId' => 'zzzzzzzzzzzzzzzz', 'name' => 'op', 'startTimeUnixNano' => '1', 'endTimeUnixNano' => '2']]]]]]], \JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('schemaMismatchProvider')]
    public function testSchemaMismatchRejected(string $json): void
    {
        $this->expectException(OtlpDecodeException::class);

        $this->decoder->decode($json);
    }

    public function testMalformedJsonRejected(): void
    {
        $this->expectException(OtlpDecodeException::class);

        $this->decoder->decode('{not valid json');
    }

    /**
     * @param array<string, mixed> $extraSpanFields
     */
    private function minimalJsonWithSpanFields(array $extraSpanFields): string
    {
        $span = array_merge([
            'traceId' => '5b8aa5a2d2c872e8321cf37308d69df2',
            'spanId' => '051581bf3cb55c13',
            'name' => 'op',
            'kind' => 1,
            'startTimeUnixNano' => '1',
            'endTimeUnixNano' => '2',
        ], $extraSpanFields);

        return json_encode([
            'resourceSpans' => [[
                'scopeSpans' => [[
                    'spans' => [$span],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function minimalJsonWithoutKind(): string
    {
        return json_encode([
            'resourceSpans' => [[
                'scopeSpans' => [[
                    'spans' => [[
                        'traceId' => '5b8aa5a2d2c872e8321cf37308d69df2',
                        'spanId' => '051581bf3cb55c13',
                        'name' => 'op',
                        'startTimeUnixNano' => '1',
                        'endTimeUnixNano' => '2',
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }
}
