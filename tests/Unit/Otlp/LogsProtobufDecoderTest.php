<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\LogsProtobufDecoder;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\ArrayValue;
use Opentelemetry\Proto\Common\V1\InstrumentationScope;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Common\V1\KeyValueList;
use Opentelemetry\Proto\Logs\V1\LogRecord;
use Opentelemetry\Proto\Logs\V1\ResourceLogs;
use Opentelemetry\Proto\Logs\V1\ScopeLogs;
use Opentelemetry\Proto\Resource\V1\Resource as OtelResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogsProtobufDecoder::class)]
final class LogsProtobufDecoderTest extends TestCase
{
    private LogsProtobufDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new LogsProtobufDecoder();
    }

    public function testDecodesMinimalValidRequest(): void
    {
        $proto = $this->buildMinimalRequest();
        $bytes = $proto->serializeToString();

        $dto = $this->decoder->decode($bytes);

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

    public function testEmptyResourceLogsAccepted(): void
    {
        $proto = new ExportLogsServiceRequest();
        $bytes = $proto->serializeToString();

        $dto = $this->decoder->decode($bytes);

        self::assertSame([], $dto->resourceLogs);
    }

    public function testAnyValueStringPreserved(): void
    {
        $body = $this->roundTripBody((new AnyValue())->setStringValue('plain'));
        self::assertSame('plain', $body->stringValue);
    }

    public function testAnyValueIntPreserved(): void
    {
        $body = $this->roundTripBody((new AnyValue())->setIntValue(42));
        self::assertSame(42, $body->intValue);
    }

    public function testAnyValueDoublePreserved(): void
    {
        $body = $this->roundTripBody((new AnyValue())->setDoubleValue(3.14));
        self::assertSame(3.14, $body->doubleValue);
    }

    public function testAnyValueBoolPreserved(): void
    {
        $body = $this->roundTripBody((new AnyValue())->setBoolValue(true));
        self::assertTrue($body->boolValue);
    }

    public function testAnyValueBytesPreserved(): void
    {
        $body = $this->roundTripBody((new AnyValue())->setBytesValue("\x00\x01\x02\xff"));
        self::assertSame("\x00\x01\x02\xff", $body->bytesValue);
    }

    public function testAnyValueArrayRecursivelyDecoded(): void
    {
        $arr = (new ArrayValue())->setValues([
            (new AnyValue())->setStringValue('a'),
            (new AnyValue())->setIntValue(7),
        ]);
        $body = $this->roundTripBody((new AnyValue())->setArrayValue($arr));

        self::assertNotNull($body->arrayValue);
        self::assertCount(2, $body->arrayValue);
        self::assertSame('a', $body->arrayValue[0]->stringValue);
        self::assertSame(7, $body->arrayValue[1]->intValue);
    }

    public function testAnyValueKvlistRecursivelyDecoded(): void
    {
        $kv = (new KeyValueList())->setValues([
            (new KeyValue())->setKey('k1')->setValue((new AnyValue())->setStringValue('v1')),
            (new KeyValue())->setKey('k2')->setValue((new AnyValue())->setIntValue(2)),
        ]);
        $body = $this->roundTripBody((new AnyValue())->setKvlistValue($kv));

        self::assertNotNull($body->kvlistValue);
        self::assertCount(2, $body->kvlistValue);
        self::assertSame('k1', $body->kvlistValue[0]->key);
        self::assertSame('v1', $body->kvlistValue[0]->value->stringValue);
        self::assertSame('k2', $body->kvlistValue[1]->key);
        self::assertSame(2, $body->kvlistValue[1]->value->intValue);
    }

    public function testTraceIdAndSpanIdRetainedAsRawBytes(): void
    {
        $traceHex = '5b8aa5a2d2c872e8321cf37308d69df2';
        $spanHex = '051581bf3cb55c13';
        $traceId = hex2bin($traceHex);
        $spanId = hex2bin($spanHex);
        self::assertNotFalse($traceId);
        self::assertNotFalse($spanId);

        $record = (new LogRecord())
            ->setTimeUnixNano(1)
            ->setTraceId($traceId)
            ->setSpanId($spanId);
        $proto = (new ExportLogsServiceRequest())->setResourceLogs([
            (new ResourceLogs())->setScopeLogs([
                (new ScopeLogs())->setLogRecords([$record]),
            ]),
        ]);

        $dto = $this->decoder->decode($proto->serializeToString());
        $decoded = $dto->resourceLogs[0]->scopeLogs[0]->logRecords[0];

        self::assertSame($traceId, $decoded->traceId);
        self::assertSame($spanId, $decoded->spanId);
    }

    public function testEmptyTraceIdAndSpanIdBecomeNull(): void
    {
        $proto = (new ExportLogsServiceRequest())->setResourceLogs([
            (new ResourceLogs())->setScopeLogs([
                (new ScopeLogs())->setLogRecords([
                    (new LogRecord())->setTimeUnixNano(1),
                ]),
            ]),
        ]);

        $dto = $this->decoder->decode($proto->serializeToString());
        $record = $dto->resourceLogs[0]->scopeLogs[0]->logRecords[0];

        self::assertNull($record->traceId);
        self::assertNull($record->spanId);
    }

    public function testResourceAndLogRecordAttributesDecoded(): void
    {
        $resource = (new OtelResource())->setAttributes([
            (new KeyValue())->setKey('service.name')->setValue((new AnyValue())->setStringValue('checkout')),
        ]);
        $record = (new LogRecord())
            ->setTimeUnixNano(1)
            ->setAttributes([
                (new KeyValue())->setKey('http.status_code')->setValue((new AnyValue())->setIntValue(500)),
            ]);
        $proto = (new ExportLogsServiceRequest())->setResourceLogs([
            (new ResourceLogs())
                ->setResource($resource)
                ->setScopeLogs([
                    (new ScopeLogs())->setLogRecords([$record]),
                ]),
        ]);

        $dto = $this->decoder->decode($proto->serializeToString());

        self::assertCount(1, $dto->resourceLogs[0]->resourceAttributes);
        self::assertSame('service.name', $dto->resourceLogs[0]->resourceAttributes[0]->key);
        self::assertSame('checkout', $dto->resourceLogs[0]->resourceAttributes[0]->value->stringValue);

        $recAttrs = $dto->resourceLogs[0]->scopeLogs[0]->logRecords[0]->attributes;
        self::assertCount(1, $recAttrs);
        self::assertSame('http.status_code', $recAttrs[0]->key);
        self::assertSame(500, $recAttrs[0]->value->intValue);
    }

    public function testOptionalRecordFieldsPreserved(): void
    {
        $proto = (new ExportLogsServiceRequest())->setResourceLogs([
            (new ResourceLogs())->setScopeLogs([
                (new ScopeLogs())->setLogRecords([
                    (new LogRecord())
                        ->setTimeUnixNano(1)
                        ->setObservedTimeUnixNano(2)
                        ->setSeverityNumber(17)
                        ->setSeverityText('ERROR')
                        ->setFlags(3)
                        ->setDroppedAttributesCount(5),
                ]),
            ]),
        ]);

        $record = $this->decoder->decode($proto->serializeToString())
            ->resourceLogs[0]->scopeLogs[0]->logRecords[0];

        self::assertSame(2, $record->observedTimeUnixNano);
        self::assertSame(17, $record->severityNumber);
        self::assertSame('ERROR', $record->severityText);
        self::assertSame(3, $record->flags);
        self::assertSame(5, $record->droppedAttributesCount);
    }

    public function testRecordWithNoBodyDecodesAsNull(): void
    {
        $proto = (new ExportLogsServiceRequest())->setResourceLogs([
            (new ResourceLogs())->setScopeLogs([
                (new ScopeLogs())->setLogRecords([
                    (new LogRecord())->setTimeUnixNano(1),
                ]),
            ]),
        ]);

        $record = $this->decoder->decode($proto->serializeToString())
            ->resourceLogs[0]->scopeLogs[0]->logRecords[0];

        self::assertNull($record->body);
    }

    public function testThrowsOnTruncatedFieldBytes(): void
    {
        // Tag 0x0a = field 1 (resource_logs), wire-type 2 (length-delimited).
        // Length varint says 64 bytes, but we only follow with 3.
        $this->expectException(OtlpDecodeException::class);

        $this->decoder->decode("\x0a\x40\x01\x02\x03");
    }

    public function testEmptySerializedRequestIsAcceptedAsEmptyDto(): void
    {
        // Wire-empty body decodes to ExportLogsServiceRequest{} — i.e. zero
        // resource_logs. The HTTP layer enforces "is the request authorised
        // and well-formed", so this layer just produces an empty DTO.
        $dto = $this->decoder->decode('');
        self::assertSame([], $dto->resourceLogs);
    }

    private function buildMinimalRequest(): ExportLogsServiceRequest
    {
        return (new ExportLogsServiceRequest())->setResourceLogs([
            (new ResourceLogs())
                ->setResource(new OtelResource())
                ->setScopeLogs([
                    (new ScopeLogs())
                        ->setScope(
                            (new InstrumentationScope())
                                ->setName('app')
                                ->setVersion('1.0.0'),
                        )
                        ->setLogRecords([
                            (new LogRecord())
                                ->setTimeUnixNano(1714752000000000000)
                                ->setSeverityNumber(9)
                                ->setSeverityText('INFO')
                                ->setBody((new AnyValue())->setStringValue('hello')),
                        ]),
                ]),
        ]);
    }

    private function roundTripBody(AnyValue $body): AnyValueDto
    {
        $proto = (new ExportLogsServiceRequest())->setResourceLogs([
            (new ResourceLogs())->setScopeLogs([
                (new ScopeLogs())->setLogRecords([
                    (new LogRecord())->setTimeUnixNano(1)->setBody($body),
                ]),
            ]),
        ]);
        $dto = $this->decoder->decode($proto->serializeToString());
        $body = $dto->resourceLogs[0]->scopeLogs[0]->logRecords[0]->body;
        self::assertNotNull($body);

        return $body;
    }
}
