<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Contract\SignalDecoder;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\ResourceSpansDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Otlp\Dto\SpanEventDto;
use App\Otlp\Dto\SpanLinkDto;
use App\Otlp\Dto\SpanStatusDto;
use App\Otlp\Exception\OtlpDecodeException;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Span\Event;
use Opentelemetry\Proto\Trace\V1\Span\Link;

/**
 * Parses OTLP/HTTP-protobuf ExportTraceServiceRequest bodies into the same
 * DTO tree the JSON decoder produces. Trace, span, and link IDs come back
 * as the raw bytes the wire delivered (16/8/16/8); empty values become null.
 */
final class TracesProtobufDecoder implements SignalDecoder
{
    public function decode(string $bytes): ExportTraceServiceRequestDto
    {
        $proto = new ExportTraceServiceRequest();
        try {
            $proto->mergeFromString($bytes);
        } catch (\Throwable $e) {
            throw new OtlpDecodeException('Failed to parse OTLP/protobuf body: '.$e->getMessage(), previous: $e);
        }

        $resourceSpans = [];
        foreach ($proto->getResourceSpans() as $rs) {
            $resourceSpans[] = $this->decodeResourceSpans($rs);
        }

        return new ExportTraceServiceRequestDto($resourceSpans);
    }

    private function decodeResourceSpans(ResourceSpans $proto): ResourceSpansDto
    {
        $resourceAttrs = [];
        if (null !== ($resource = $proto->getResource())) {
            foreach ($resource->getAttributes() as $kv) {
                $resourceAttrs[] = $this->decodeKeyValue($kv);
            }
        }

        $scopeSpans = [];
        foreach ($proto->getScopeSpans() as $ss) {
            $scopeSpans[] = $this->decodeScopeSpans($ss);
        }

        $schemaUrl = '' !== $proto->getSchemaUrl() ? $proto->getSchemaUrl() : null;

        return new ResourceSpansDto($resourceAttrs, $scopeSpans, $schemaUrl);
    }

    private function decodeScopeSpans(ScopeSpans $proto): ScopeSpansDto
    {
        $scopeName = null;
        $scopeVersion = null;
        if (null !== ($scope = $proto->getScope())) {
            $scopeName = '' !== $scope->getName() ? $scope->getName() : null;
            $scopeVersion = '' !== $scope->getVersion() ? $scope->getVersion() : null;
        }

        $schemaUrl = '' !== $proto->getSchemaUrl() ? $proto->getSchemaUrl() : null;

        $spans = [];
        foreach ($proto->getSpans() as $span) {
            $spans[] = $this->decodeSpan($span);
        }

        return new ScopeSpansDto($scopeName, $scopeVersion, $spans, $schemaUrl);
    }

    private function decodeSpan(Span $proto): SpanDto
    {
        $traceId = $proto->getTraceId();
        $spanId = $proto->getSpanId();
        if ('' === $traceId) {
            throw OtlpDecodeException::schemaMismatch('Span.trace_id is required.');
        }
        if ('' === $spanId) {
            throw OtlpDecodeException::schemaMismatch('Span.span_id is required.');
        }
        $parentSpanId = $proto->getParentSpanId();
        $parentSpanId = '' === $parentSpanId ? null : $parentSpanId;

        $traceState = '' !== $proto->getTraceState() ? $proto->getTraceState() : null;
        $flags = 0 === $proto->getFlags() ? null : $proto->getFlags();

        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }

        $events = [];
        foreach ($proto->getEvents() as $event) {
            $events[] = $this->decodeEvent($event);
        }

        $links = [];
        foreach ($proto->getLinks() as $link) {
            $links[] = $this->decodeLink($link);
        }

        $status = null;
        if (null !== ($protoStatus = $proto->getStatus())) {
            $code = $protoStatus->getCode();
            $message = $protoStatus->getMessage();
            // Default-initialised Status (code=UNSET=0, message='') means
            // "no status set"; treat as null for query consistency.
            if (0 !== $code || '' !== $message) {
                $status = new SpanStatusDto($code, '' !== $message ? $message : null);
            }
        }

        return new SpanDto(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            traceState: $traceState,
            flags: $flags,
            name: $proto->getName(),
            kind: $proto->getKind(),
            startTimeUnixNano: $proto->getStartTimeUnixNano(),
            endTimeUnixNano: $proto->getEndTimeUnixNano(),
            attributes: $attributes,
            events: $events,
            links: $links,
            status: $status,
            droppedAttributesCount: $proto->getDroppedAttributesCount(),
            droppedEventsCount: $proto->getDroppedEventsCount(),
            droppedLinksCount: $proto->getDroppedLinksCount(),
        );
    }

    private function decodeEvent(Event $proto): SpanEventDto
    {
        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }

        return new SpanEventDto(
            timeUnixNano: $proto->getTimeUnixNano(),
            name: $proto->getName(),
            attributes: $attributes,
            droppedAttributesCount: $proto->getDroppedAttributesCount(),
        );
    }

    private function decodeLink(Link $proto): SpanLinkDto
    {
        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }

        return new SpanLinkDto(
            traceId: $proto->getTraceId(),
            spanId: $proto->getSpanId(),
            traceState: '' !== $proto->getTraceState() ? $proto->getTraceState() : null,
            attributes: $attributes,
            droppedAttributesCount: $proto->getDroppedAttributesCount(),
            flags: 0 === $proto->getFlags() ? null : $proto->getFlags(),
        );
    }

    private function decodeKeyValue(KeyValue $proto): KeyValueDto
    {
        $value = null !== ($protoValue = $proto->getValue()) && $this->anyValueIsSet($protoValue)
            ? $this->decodeAnyValue($protoValue)
            : new AnyValueDto();

        return new KeyValueDto($proto->getKey(), $value);
    }

    private function decodeAnyValue(AnyValue $proto): AnyValueDto
    {
        return match ($proto->getValue()) {
            'string_value' => AnyValueDto::string($proto->getStringValue()),
            'int_value' => AnyValueDto::int($proto->getIntValue()),
            'double_value' => AnyValueDto::double($proto->getDoubleValue()),
            'bool_value' => AnyValueDto::bool($proto->getBoolValue()),
            'bytes_value' => AnyValueDto::bytes($proto->getBytesValue()),
            'array_value' => $this->decodeArrayValue($proto),
            'kvlist_value' => $this->decodeKvlistValue($proto),
            default => new AnyValueDto(),
        };
    }

    private function decodeArrayValue(AnyValue $proto): AnyValueDto
    {
        $array = $proto->getArrayValue();
        $items = [];
        if (null !== $array) {
            foreach ($array->getValues() as $entry) {
                $items[] = $this->decodeAnyValue($entry);
            }
        }

        return AnyValueDto::array($items);
    }

    private function decodeKvlistValue(AnyValue $proto): AnyValueDto
    {
        $list = $proto->getKvlistValue();
        $items = [];
        if (null !== $list) {
            foreach ($list->getValues() as $entry) {
                $items[] = $this->decodeKeyValue($entry);
            }
        }

        return AnyValueDto::kvlist($items);
    }

    private function anyValueIsSet(AnyValue $proto): bool
    {
        return '' !== $proto->getValue();
    }
}
