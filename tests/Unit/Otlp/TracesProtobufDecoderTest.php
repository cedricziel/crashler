<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\TracesProtobufDecoder;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\InstrumentationScope;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Resource\V1\Resource as OtelResource;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Span\Event;
use Opentelemetry\Proto\Trace\V1\Span\Link;
use Opentelemetry\Proto\Trace\V1\Span\SpanKind;
use Opentelemetry\Proto\Trace\V1\Status;
use Opentelemetry\Proto\Trace\V1\Status\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TracesProtobufDecoder::class)]
final class TracesProtobufDecoderTest extends TestCase
{
    private TracesProtobufDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new TracesProtobufDecoder();
    }

    public function testDecodesMinimalRequest(): void
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');

        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())
                ->setResource(new OtelResource())
                ->setScopeSpans([
                    (new ScopeSpans())
                        ->setScope((new InstrumentationScope())->setName('app')->setVersion('1.0'))
                        ->setSpans([
                            (new Span())
                                ->setTraceId($traceId)
                                ->setSpanId($spanId)
                                ->setName('GET /orders/:id')
                                ->setKind(SpanKind::SPAN_KIND_SERVER)
                                ->setStartTimeUnixNano(1714752000000000000)
                                ->setEndTimeUnixNano(1714752000050000000),
                        ]),
                ]),
        ]);

        $dto = $this->decoder->decode($proto->serializeToString());

        self::assertInstanceOf(ExportTraceServiceRequestDto::class, $dto);
        $span = $dto->resourceSpans[0]->scopeSpans[0]->spans[0];
        self::assertSame($traceId, $span->traceId);
        self::assertSame($spanId, $span->spanId);
        self::assertSame('GET /orders/:id', $span->name);
        self::assertSame(2, $span->kind);
        self::assertSame(1714752000000000000, $span->startTimeUnixNano);
        self::assertSame(1714752000050000000, $span->endTimeUnixNano);
    }

    public function testEmptyResourceSpansAccepted(): void
    {
        $proto = new ExportTraceServiceRequest();
        $dto = $this->decoder->decode($proto->serializeToString());

        self::assertSame([], $dto->resourceSpans);
    }

    public function testEmptyParentSpanIdBecomesNull(): void
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');
        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())->setSpans([
                    (new Span())
                        ->setTraceId($traceId)
                        ->setSpanId($spanId)
                        ->setName('op')
                        ->setStartTimeUnixNano(1)
                        ->setEndTimeUnixNano(2),
                ]),
            ]),
        ]);

        $span = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertNull($span->parentSpanId);
    }

    public function testParentSpanIdRoundTrips(): void
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');
        $parentSpanId = (string) hex2bin('aabbccddeeff1122');

        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())->setSpans([
                    (new Span())
                        ->setTraceId($traceId)
                        ->setSpanId($spanId)
                        ->setParentSpanId($parentSpanId)
                        ->setName('op')
                        ->setStartTimeUnixNano(1)
                        ->setEndTimeUnixNano(2),
                ]),
            ]),
        ]);

        $span = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertSame($parentSpanId, $span->parentSpanId);
    }

    public function testStatusRoundTrips(): void
    {
        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())->setSpans([
                    $this->minimalSpan()
                        ->setStatus((new Status())->setCode(StatusCode::STATUS_CODE_ERROR)->setMessage('boom')),
                ]),
            ]),
        ]);

        $span = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertNotNull($span->status);
        self::assertSame(2, $span->status->code);
        self::assertSame('boom', $span->status->message);
    }

    public function testStatusUnsetBecomesNull(): void
    {
        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())->setSpans([$this->minimalSpan()]),
            ]),
        ]);

        $span = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0]->spans[0];

        // Proto always returns a Status object (default), but we map all-default
        // (code=UNSET=0, message='') to null per spec scenario.
        self::assertNull($span->status);
    }

    public function testEventsRoundTrip(): void
    {
        $event = (new Event())
            ->setTimeUnixNano(1714752000000000010)
            ->setName('cache.miss')
            ->setAttributes([
                (new KeyValue())->setKey('cache.key')->setValue((new AnyValue())->setStringValue('user:42')),
            ]);

        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())->setSpans([
                    $this->minimalSpan()->setEvents([$event]),
                ]),
            ]),
        ]);

        $span = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertCount(1, $span->events);
        self::assertSame('cache.miss', $span->events[0]->name);
        self::assertSame(1714752000000000010, $span->events[0]->timeUnixNano);
        self::assertSame('user:42', $span->events[0]->attributes[0]->value->stringValue);
    }

    public function testLinksRoundTrip(): void
    {
        $linkTrace = (string) hex2bin('0123456789abcdef0123456789abcdef');
        $linkSpan = (string) hex2bin('fedcba9876543210');
        $link = (new Link())
            ->setTraceId($linkTrace)
            ->setSpanId($linkSpan)
            ->setTraceState('rojo=00f')
            ->setFlags(1);

        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())->setSpans([
                    $this->minimalSpan()->setLinks([$link]),
                ]),
            ]),
        ]);

        $span = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertCount(1, $span->links);
        self::assertSame($linkTrace, $span->links[0]->traceId);
        self::assertSame($linkSpan, $span->links[0]->spanId);
        self::assertSame('rojo=00f', $span->links[0]->traceState);
        self::assertSame(1, $span->links[0]->flags);
    }

    public function testEmptyEventsAndLinksDefaultToEmptyArrays(): void
    {
        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())->setSpans([$this->minimalSpan()]),
            ]),
        ]);

        $span = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertSame([], $span->events);
        self::assertSame([], $span->links);
    }

    public function testScopeSchemaUrlRoundTrips(): void
    {
        $proto = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([
                (new ScopeSpans())
                    ->setSchemaUrl('https://opentelemetry.io/schemas/1.30.0')
                    ->setSpans([$this->minimalSpan()]),
            ]),
        ]);

        $scope = $this->decoder->decode($proto->serializeToString())->resourceSpans[0]->scopeSpans[0];

        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $scope->schemaUrl);
    }

    public function testThrowsOnTruncatedFieldBytes(): void
    {
        $this->expectException(OtlpDecodeException::class);

        $this->decoder->decode("\x0a\x40\x01\x02\x03");
    }

    private function minimalSpan(): Span
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');

        return (new Span())
            ->setTraceId($traceId)
            ->setSpanId($spanId)
            ->setName('op')
            ->setKind(SpanKind::SPAN_KIND_INTERNAL)
            ->setStartTimeUnixNano(1)
            ->setEndTimeUnixNano(2);
    }
}
