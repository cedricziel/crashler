<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\LogsJsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogsJsonDecoder::class)]
#[CoversClass(OtlpDecodeException::class)]
final class LogsJsonDecoderTest extends TestCase
{
    private LogsJsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new LogsJsonDecoder();
    }

    public function testDecodesMinimalValidRequest(): void
    {
        $json = json_encode([
            'resourceLogs' => [[
                'resource' => ['attributes' => []],
                'scopeLogs' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0.0'],
                    'logRecords' => [[
                        'timeUnixNano' => '1714752000000000000',
                        'severityNumber' => 9,
                        'severityText' => 'INFO',
                        'body' => ['stringValue' => 'hello'],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $dto = $this->decoder->decode($json);

        self::assertInstanceOf(ExportLogsServiceRequestDto::class, $dto);
        self::assertCount(1, $dto->resourceLogs);
        $resource = $dto->resourceLogs[0];
        self::assertCount(1, $resource->scopeLogs);
        $scope = $resource->scopeLogs[0];
        self::assertSame('app', $scope->scopeName);
        self::assertSame('1.0.0', $scope->scopeVersion);
        self::assertCount(1, $scope->logRecords);
        $record = $scope->logRecords[0];
        self::assertSame(1714752000000000000, $record->timeUnixNano);
        self::assertSame(9, $record->severityNumber);
        self::assertSame('INFO', $record->severityText);
        self::assertNotNull($record->body);
        self::assertSame('hello', $record->body->stringValue);
    }

    public function testTimeUnixNanoAcceptedAsNumber(): void
    {
        $json = json_encode([
            'resourceLogs' => [[
                'scopeLogs' => [[
                    'logRecords' => [[
                        'timeUnixNano' => 1714752000000000000,
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $dto = $this->decoder->decode($json);

        self::assertSame(1714752000000000000, $dto->resourceLogs[0]->scopeLogs[0]->logRecords[0]->timeUnixNano);
    }

    public function testTimeUnixNanoAsStringAndNumberProduceIdenticalIntegers(): void
    {
        $stringForm = $this->decoder->decode(json_encode([
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [[
                'timeUnixNano' => '1234567890123456789',
            ]]]]]],
        ], \JSON_THROW_ON_ERROR));

        $numberForm = $this->decoder->decode(json_encode([
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [[
                'timeUnixNano' => 1234567890123456789,
            ]]]]]],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(
            $stringForm->resourceLogs[0]->scopeLogs[0]->logRecords[0]->timeUnixNano,
            $numberForm->resourceLogs[0]->scopeLogs[0]->logRecords[0]->timeUnixNano,
        );
    }

    public function testHexTraceIdAndSpanIdDecodedToRawBytes(): void
    {
        $traceHex = '5b8aa5a2d2c872e8321cf37308d69df2';
        $spanHex = '051581bf3cb55c13';
        $json = json_encode([
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [[
                'timeUnixNano' => '1',
                'traceId' => $traceHex,
                'spanId' => $spanHex,
            ]]]]]],
        ], \JSON_THROW_ON_ERROR);

        $dto = $this->decoder->decode($json);
        $record = $dto->resourceLogs[0]->scopeLogs[0]->logRecords[0];

        self::assertSame(hex2bin($traceHex), $record->traceId);
        self::assertSame(hex2bin($spanHex), $record->spanId);
    }

    public function testAnyValueStringPreserved(): void
    {
        $body = $this->decodeBody(['stringValue' => 'plain']);
        self::assertSame('plain', $body->stringValue);
    }

    public function testAnyValueIntAsStringPreserved(): void
    {
        $body = $this->decodeBody(['intValue' => '42']);
        self::assertSame(42, $body->intValue);
    }

    public function testAnyValueIntAsNumberPreserved(): void
    {
        $body = $this->decodeBody(['intValue' => 42]);
        self::assertSame(42, $body->intValue);
    }

    public function testAnyValueDoublePreserved(): void
    {
        $body = $this->decodeBody(['doubleValue' => 3.14]);
        self::assertSame(3.14, $body->doubleValue);
    }

    public function testAnyValueBoolPreserved(): void
    {
        $body = $this->decodeBody(['boolValue' => true]);
        self::assertTrue($body->boolValue);
    }

    public function testAnyValueBytesDecodedFromBase64(): void
    {
        $body = $this->decodeBody(['bytesValue' => base64_encode("\x00\x01\x02\xff")]);
        self::assertSame("\x00\x01\x02\xff", $body->bytesValue);
    }

    public function testAnyValueArrayRecursivelyDecoded(): void
    {
        $body = $this->decodeBody([
            'arrayValue' => ['values' => [
                ['stringValue' => 'a'],
                ['intValue' => '7'],
            ]],
        ]);

        self::assertNotNull($body->arrayValue);
        self::assertCount(2, $body->arrayValue);
        self::assertSame('a', $body->arrayValue[0]->stringValue);
        self::assertSame(7, $body->arrayValue[1]->intValue);
    }

    public function testAnyValueKvlistRecursivelyDecoded(): void
    {
        $body = $this->decodeBody([
            'kvlistValue' => ['values' => [
                ['key' => 'k1', 'value' => ['stringValue' => 'v1']],
                ['key' => 'k2', 'value' => ['intValue' => '2']],
            ]],
        ]);

        self::assertNotNull($body->kvlistValue);
        self::assertCount(2, $body->kvlistValue);
        self::assertSame('k1', $body->kvlistValue[0]->key);
        self::assertSame('v1', $body->kvlistValue[0]->value->stringValue);
        self::assertSame('k2', $body->kvlistValue[1]->key);
        self::assertSame(2, $body->kvlistValue[1]->value->intValue);
    }

    public function testResourceAndLogRecordAttributesDecoded(): void
    {
        $json = json_encode([
            'resourceLogs' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ]],
                'scopeLogs' => [['logRecords' => [[
                    'timeUnixNano' => '1',
                    'attributes' => [
                        ['key' => 'http.status_code', 'value' => ['intValue' => '500']],
                    ],
                ]]]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $dto = $this->decoder->decode($json);

        $resource = $dto->resourceLogs[0];
        self::assertCount(1, $resource->resourceAttributes);
        self::assertSame('service.name', $resource->resourceAttributes[0]->key);
        self::assertSame('checkout', $resource->resourceAttributes[0]->value->stringValue);

        $record = $resource->scopeLogs[0]->logRecords[0];
        self::assertCount(1, $record->attributes);
        self::assertSame('http.status_code', $record->attributes[0]->key);
        self::assertSame(500, $record->attributes[0]->value->intValue);
    }

    public function testOptionalFieldsBecomeNull(): void
    {
        $json = json_encode([
            'resourceLogs' => [[
                'scopeLogs' => [['logRecords' => [[
                    'timeUnixNano' => '1',
                ]]]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $record = $this->decoder->decode($json)->resourceLogs[0]->scopeLogs[0]->logRecords[0];

        self::assertNull($record->observedTimeUnixNano);
        self::assertNull($record->severityNumber);
        self::assertNull($record->severityText);
        self::assertNull($record->body);
        self::assertNull($record->traceId);
        self::assertNull($record->spanId);
        self::assertNull($record->flags);
        self::assertSame([], $record->attributes);
        self::assertSame(0, $record->droppedAttributesCount);
    }

    public function testMultipleResourceLogsDecoded(): void
    {
        $json = json_encode([
            'resourceLogs' => [
                ['resource' => ['attributes' => [['key' => 'a', 'value' => ['stringValue' => '1']]]],
                 'scopeLogs' => [['logRecords' => [['timeUnixNano' => '1']]]]],
                ['resource' => ['attributes' => [['key' => 'b', 'value' => ['stringValue' => '2']]]],
                 'scopeLogs' => [['logRecords' => [['timeUnixNano' => '2']]]]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $dto = $this->decoder->decode($json);

        self::assertCount(2, $dto->resourceLogs);
        self::assertSame('a', $dto->resourceLogs[0]->resourceAttributes[0]->key);
        self::assertSame('b', $dto->resourceLogs[1]->resourceAttributes[0]->key);
    }

    public function testEmptyResourceLogsAccepted(): void
    {
        $json = json_encode(['resourceLogs' => []], \JSON_THROW_ON_ERROR);
        $dto = $this->decoder->decode($json);

        self::assertSame([], $dto->resourceLogs);
    }

    public function testThrowsOnMalformedJson(): void
    {
        $this->expectException(OtlpDecodeException::class);
        $this->expectExceptionMessageMatches('/json/i');

        $this->decoder->decode('{not valid json');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function schemaMismatchProvider(): iterable
    {
        yield 'missing resourceLogs' => ['{"foo":"bar"}'];
        yield 'resourceLogs not array' => ['{"resourceLogs":"oops"}'];
        yield 'scopeLogs not array' => [json_encode(['resourceLogs' => [['scopeLogs' => 'oops']]], \JSON_THROW_ON_ERROR)];
        yield 'logRecords not array' => [json_encode(['resourceLogs' => [['scopeLogs' => [['logRecords' => 'oops']]]]], \JSON_THROW_ON_ERROR)];
        yield 'timeUnixNano missing on a record' => [json_encode(['resourceLogs' => [['scopeLogs' => [['logRecords' => [[]]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'timeUnixNano wrong type' => [json_encode(['resourceLogs' => [['scopeLogs' => [['logRecords' => [['timeUnixNano' => true]]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'traceId wrong length' => [json_encode(['resourceLogs' => [['scopeLogs' => [['logRecords' => [['timeUnixNano' => '1', 'traceId' => 'cafe']]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'spanId non-hex' => [json_encode(['resourceLogs' => [['scopeLogs' => [['logRecords' => [['timeUnixNano' => '1', 'spanId' => 'zzzzzzzzzzzzzzzz']]]]]]], \JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('schemaMismatchProvider')]
    public function testThrowsOnSchemaMismatch(string $json): void
    {
        $this->expectException(OtlpDecodeException::class);

        $this->decoder->decode($json);
    }

    /**
     * @param array<string, mixed> $bodyValue
     */
    private function decodeBody(array $bodyValue): AnyValueDto
    {
        $json = json_encode([
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [[
                'timeUnixNano' => '1',
                'body' => $bodyValue,
            ]]]]]],
        ], \JSON_THROW_ON_ERROR);

        $body = $this->decoder->decode($json)->resourceLogs[0]->scopeLogs[0]->logRecords[0]->body;
        self::assertNotNull($body);

        return $body;
    }
}
