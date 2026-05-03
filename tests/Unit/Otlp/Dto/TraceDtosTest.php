<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp\Dto;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\ResourceSpansDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Otlp\Dto\SpanEventDto;
use App\Otlp\Dto\SpanLinkDto;
use App\Otlp\Dto\SpanStatusDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanDto::class)]
#[CoversClass(SpanEventDto::class)]
#[CoversClass(SpanLinkDto::class)]
#[CoversClass(SpanStatusDto::class)]
#[CoversClass(ScopeSpansDto::class)]
#[CoversClass(ResourceSpansDto::class)]
#[CoversClass(ExportTraceServiceRequestDto::class)]
final class TraceDtosTest extends TestCase
{
    public function testSpanStatusDto(): void
    {
        $status = new SpanStatusDto(2, 'connection refused');
        self::assertSame(2, $status->code);
        self::assertSame('connection refused', $status->message);
    }

    public function testSpanStatusDtoMessageOptional(): void
    {
        $status = new SpanStatusDto(0, null);
        self::assertSame(0, $status->code);
        self::assertNull($status->message);
    }

    public function testSpanEventDto(): void
    {
        $event = new SpanEventDto(
            timeUnixNano: 1714752000000000000,
            name: 'cache.miss',
            attributes: [new KeyValueDto('cache.key', AnyValueDto::string('user:42'))],
            droppedAttributesCount: 0,
        );

        self::assertSame(1714752000000000000, $event->timeUnixNano);
        self::assertSame('cache.miss', $event->name);
        self::assertCount(1, $event->attributes);
        self::assertSame(0, $event->droppedAttributesCount);
    }

    public function testSpanLinkDto(): void
    {
        $traceId = hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = hex2bin('051581bf3cb55c13');
        self::assertNotFalse($traceId);
        self::assertNotFalse($spanId);

        $link = new SpanLinkDto(
            traceId: $traceId,
            spanId: $spanId,
            traceState: 'rojo=00f067aa0ba902b7',
            attributes: [],
            droppedAttributesCount: 0,
            flags: 1,
        );

        self::assertSame($traceId, $link->traceId);
        self::assertSame($spanId, $link->spanId);
        self::assertSame('rojo=00f067aa0ba902b7', $link->traceState);
        self::assertSame(1, $link->flags);
    }

    public function testSpanDtoCarriesEverySpanField(): void
    {
        $traceId = hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = hex2bin('051581bf3cb55c13');
        $parentSpanId = hex2bin('aabbccddeeff1122');
        self::assertNotFalse($traceId);
        self::assertNotFalse($spanId);
        self::assertNotFalse($parentSpanId);

        $span = new SpanDto(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            traceState: 'k=v',
            flags: 1,
            name: 'GET /orders/:id',
            kind: 2,
            startTimeUnixNano: 1714752000000000000,
            endTimeUnixNano: 1714752000050000000,
            attributes: [new KeyValueDto('http.request.method', AnyValueDto::string('GET'))],
            events: [],
            links: [],
            status: new SpanStatusDto(1, null),
            droppedAttributesCount: 0,
            droppedEventsCount: 0,
            droppedLinksCount: 0,
        );

        self::assertSame($traceId, $span->traceId);
        self::assertSame($spanId, $span->spanId);
        self::assertSame($parentSpanId, $span->parentSpanId);
        self::assertSame('k=v', $span->traceState);
        self::assertSame(1, $span->flags);
        self::assertSame('GET /orders/:id', $span->name);
        self::assertSame(2, $span->kind);
        self::assertSame(1714752000000000000, $span->startTimeUnixNano);
        self::assertSame(1714752000050000000, $span->endTimeUnixNano);
        self::assertCount(1, $span->attributes);
        self::assertSame([], $span->events);
        self::assertSame([], $span->links);
        self::assertNotNull($span->status);
        self::assertSame(1, $span->status->code);
    }

    public function testSpanDtoOptionalFieldsCanBeNull(): void
    {
        $traceId = hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = hex2bin('051581bf3cb55c13');
        self::assertNotFalse($traceId);
        self::assertNotFalse($spanId);

        $span = new SpanDto(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: null,
            traceState: null,
            flags: null,
            name: 'op',
            kind: 0,
            startTimeUnixNano: 1,
            endTimeUnixNano: 2,
            attributes: [],
            events: [],
            links: [],
            status: null,
            droppedAttributesCount: 0,
            droppedEventsCount: 0,
            droppedLinksCount: 0,
        );

        self::assertNull($span->parentSpanId);
        self::assertNull($span->traceState);
        self::assertNull($span->flags);
        self::assertNull($span->status);
    }

    public function testScopeSpansDto(): void
    {
        $scope = new ScopeSpansDto(
            scopeName: 'my-app',
            scopeVersion: '1.0',
            spans: [],
            schemaUrl: 'https://opentelemetry.io/schemas/1.30.0',
        );

        self::assertSame('my-app', $scope->scopeName);
        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $scope->schemaUrl);
        self::assertSame([], $scope->spans);
    }

    public function testResourceSpansDto(): void
    {
        $resource = new ResourceSpansDto(
            resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
            scopeSpans: [],
        );

        self::assertCount(1, $resource->resourceAttributes);
        self::assertSame([], $resource->scopeSpans);
        self::assertNull($resource->schemaUrl);
    }

    public function testExportTraceServiceRequestDto(): void
    {
        $request = new ExportTraceServiceRequestDto([]);
        self::assertSame([], $request->resourceSpans);
    }
}
