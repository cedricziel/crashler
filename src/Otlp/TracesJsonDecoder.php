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

/**
 * Parses OTLP/HTTP-JSON ExportTraceServiceRequest bodies into the DTO tree.
 *
 * Mirrors {@see LogsJsonDecoder}'s rules: int64 fields accepted as either
 * JSON number or numeric string, traceId/spanId/parentSpanId are lowercase
 * hex strings (32/16/16 chars), AnyValue variants preserved across span
 * attributes AND event attributes.
 */
final class TracesJsonDecoder implements SignalDecoder
{
    public function decode(string $json): ExportTraceServiceRequestDto
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw OtlpDecodeException::malformedJson($e);
        }

        if (!\is_array($decoded)) {
            throw OtlpDecodeException::schemaMismatch('top-level value must be an object.');
        }

        $resourceSpansRaw = $decoded['resourceSpans'] ?? null;
        if (!\is_array($resourceSpansRaw)) {
            throw OtlpDecodeException::schemaMismatch('"resourceSpans" must be an array.');
        }

        $resourceSpans = [];
        foreach ($resourceSpansRaw as $i => $entry) {
            $resourceSpans[] = $this->decodeResourceSpans($entry, "resourceSpans[$i]");
        }

        return new ExportTraceServiceRequestDto($resourceSpans);
    }

    private function decodeResourceSpans($raw, string $path): ResourceSpansDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $resourceAttrs = [];
        if (isset($raw['resource']) && \is_array($raw['resource'])) {
            $attrs = $raw['resource']['attributes'] ?? [];
            if (!\is_array($attrs)) {
                throw OtlpDecodeException::schemaMismatch("$path.resource.attributes must be an array.");
            }
            foreach ($attrs as $i => $kv) {
                $resourceAttrs[] = $this->decodeKeyValue($kv, "$path.resource.attributes[$i]");
            }
        }

        $scopeSpansRaw = $raw['scopeSpans'] ?? null;
        if (!\is_array($scopeSpansRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.scopeSpans must be an array.");
        }

        $scopeSpans = [];
        foreach ($scopeSpansRaw as $i => $entry) {
            $scopeSpans[] = $this->decodeScopeSpans($entry, "$path.scopeSpans[$i]");
        }

        $schemaUrl = $this->stringOrNull($raw['schemaUrl'] ?? null, "$path.schemaUrl");

        return new ResourceSpansDto($resourceAttrs, $scopeSpans, $schemaUrl);
    }

    private function decodeScopeSpans($raw, string $path): ScopeSpansDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $scopeName = null;
        $scopeVersion = null;
        if (isset($raw['scope']) && \is_array($raw['scope'])) {
            $scopeName = $this->stringOrNull($raw['scope']['name'] ?? null, "$path.scope.name");
            $scopeVersion = $this->stringOrNull($raw['scope']['version'] ?? null, "$path.scope.version");
        }

        $schemaUrl = $this->stringOrNull($raw['schemaUrl'] ?? null, "$path.schemaUrl");

        $spansRaw = $raw['spans'] ?? null;
        if (!\is_array($spansRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.spans must be an array.");
        }

        $spans = [];
        foreach ($spansRaw as $i => $entry) {
            $spans[] = $this->decodeSpan($entry, "$path.spans[$i]");
        }

        return new ScopeSpansDto($scopeName, $scopeVersion, $spans, $schemaUrl);
    }

    private function decodeSpan($raw, string $path): SpanDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $traceId = $this->hexBytes($raw['traceId'] ?? null, 32, "$path.traceId");
        $spanId = $this->hexBytes($raw['spanId'] ?? null, 16, "$path.spanId");
        $parentSpanId = $this->hexBytesOrNull($raw['parentSpanId'] ?? null, 16, "$path.parentSpanId");
        $traceState = $this->stringOrNull($raw['traceState'] ?? null, "$path.traceState");
        $flags = isset($raw['flags']) ? $this->int32($raw['flags'], "$path.flags") : null;

        $name = $raw['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            throw OtlpDecodeException::schemaMismatch("$path.name is required.");
        }

        $kind = isset($raw['kind']) ? $this->int32($raw['kind'], "$path.kind") : 0;

        if (!\array_key_exists('startTimeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.startTimeUnixNano is required.");
        }
        if (!\array_key_exists('endTimeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.endTimeUnixNano is required.");
        }
        $start = $this->int64($raw['startTimeUnixNano'], "$path.startTimeUnixNano");
        $end = $this->int64($raw['endTimeUnixNano'], "$path.endTimeUnixNano");

        $attributesRaw = $raw['attributes'] ?? [];
        if (!\is_array($attributesRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.attributes must be an array.");
        }
        $attributes = [];
        foreach ($attributesRaw as $i => $kv) {
            $attributes[] = $this->decodeKeyValue($kv, "$path.attributes[$i]");
        }

        $events = [];
        $eventsRaw = $raw['events'] ?? [];
        if (!\is_array($eventsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.events must be an array.");
        }
        foreach ($eventsRaw as $i => $entry) {
            $events[] = $this->decodeEvent($entry, "$path.events[$i]");
        }

        $links = [];
        $linksRaw = $raw['links'] ?? [];
        if (!\is_array($linksRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.links must be an array.");
        }
        foreach ($linksRaw as $i => $entry) {
            $links[] = $this->decodeLink($entry, "$path.links[$i]");
        }

        $status = null;
        if (isset($raw['status']) && \is_array($raw['status'])) {
            $status = new SpanStatusDto(
                code: isset($raw['status']['code']) ? $this->int32($raw['status']['code'], "$path.status.code") : 0,
                message: $this->stringOrNull($raw['status']['message'] ?? null, "$path.status.message"),
            );
        }

        $droppedAttrs = isset($raw['droppedAttributesCount']) ? $this->int32($raw['droppedAttributesCount'], "$path.droppedAttributesCount") : 0;
        $droppedEvents = isset($raw['droppedEventsCount']) ? $this->int32($raw['droppedEventsCount'], "$path.droppedEventsCount") : 0;
        $droppedLinks = isset($raw['droppedLinksCount']) ? $this->int32($raw['droppedLinksCount'], "$path.droppedLinksCount") : 0;

        return new SpanDto(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            traceState: $traceState,
            flags: $flags,
            name: $name,
            kind: $kind,
            startTimeUnixNano: $start,
            endTimeUnixNano: $end,
            attributes: $attributes,
            events: $events,
            links: $links,
            status: $status,
            droppedAttributesCount: $droppedAttrs,
            droppedEventsCount: $droppedEvents,
            droppedLinksCount: $droppedLinks,
        );
    }

    private function decodeEvent($raw, string $path): SpanEventDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        $name = $this->stringOrNull($raw['name'] ?? null, "$path.name") ?? '';
        $time = $this->int64($raw['timeUnixNano'] ?? 0, "$path.timeUnixNano");
        $attributesRaw = $raw['attributes'] ?? [];
        if (!\is_array($attributesRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.attributes must be an array.");
        }
        $attributes = [];
        foreach ($attributesRaw as $i => $kv) {
            $attributes[] = $this->decodeKeyValue($kv, "$path.attributes[$i]");
        }
        $dropped = isset($raw['droppedAttributesCount']) ? $this->int32($raw['droppedAttributesCount'], "$path.droppedAttributesCount") : 0;

        return new SpanEventDto($time, $name, $attributes, $dropped);
    }

    private function decodeLink($raw, string $path): SpanLinkDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        $traceId = $this->hexBytes($raw['traceId'] ?? null, 32, "$path.traceId");
        $spanId = $this->hexBytes($raw['spanId'] ?? null, 16, "$path.spanId");
        $traceState = $this->stringOrNull($raw['traceState'] ?? null, "$path.traceState");
        $attributesRaw = $raw['attributes'] ?? [];
        if (!\is_array($attributesRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.attributes must be an array.");
        }
        $attributes = [];
        foreach ($attributesRaw as $i => $kv) {
            $attributes[] = $this->decodeKeyValue($kv, "$path.attributes[$i]");
        }
        $dropped = isset($raw['droppedAttributesCount']) ? $this->int32($raw['droppedAttributesCount'], "$path.droppedAttributesCount") : 0;
        $flags = isset($raw['flags']) ? $this->int32($raw['flags'], "$path.flags") : null;

        return new SpanLinkDto($traceId, $spanId, $traceState, $attributes, $dropped, $flags);
    }

    private function decodeKeyValue($raw, string $path): KeyValueDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!isset($raw['key']) || !\is_string($raw['key'])) {
            throw OtlpDecodeException::schemaMismatch("$path.key is required and must be a string.");
        }
        $value = $this->decodeAnyValue($raw['value'] ?? [], "$path.value");

        return new KeyValueDto($raw['key'], $value);
    }

    private function decodeAnyValue($raw, string $path): AnyValueDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object (AnyValue).");
        }

        if (\array_key_exists('stringValue', $raw)) {
            return AnyValueDto::string($this->stringOrNull($raw['stringValue'], "$path.stringValue") ?? '');
        }
        if (\array_key_exists('intValue', $raw)) {
            return AnyValueDto::int($this->int64($raw['intValue'], "$path.intValue"));
        }
        if (\array_key_exists('doubleValue', $raw)) {
            $v = $raw['doubleValue'];
            if (!\is_int($v) && !\is_float($v)) {
                throw OtlpDecodeException::schemaMismatch("$path.doubleValue must be a number.");
            }

            return AnyValueDto::double((float) $v);
        }
        if (\array_key_exists('boolValue', $raw)) {
            $v = $raw['boolValue'];
            if (!\is_bool($v)) {
                throw OtlpDecodeException::schemaMismatch("$path.boolValue must be a boolean.");
            }

            return AnyValueDto::bool($v);
        }
        if (\array_key_exists('bytesValue', $raw)) {
            $v = $raw['bytesValue'];
            if (!\is_string($v)) {
                throw OtlpDecodeException::schemaMismatch("$path.bytesValue must be a base64 string.");
            }
            $decoded = base64_decode($v, true);
            if (false === $decoded) {
                throw OtlpDecodeException::schemaMismatch("$path.bytesValue is not valid base64.");
            }

            return AnyValueDto::bytes($decoded);
        }
        if (\array_key_exists('arrayValue', $raw)) {
            $av = $raw['arrayValue'];
            if (!\is_array($av) || !\is_array($av['values'] ?? null)) {
                throw OtlpDecodeException::schemaMismatch("$path.arrayValue.values must be an array.");
            }
            $items = [];
            foreach ($av['values'] as $i => $entry) {
                $items[] = $this->decodeAnyValue($entry, "$path.arrayValue.values[$i]");
            }

            return AnyValueDto::array($items);
        }
        if (\array_key_exists('kvlistValue', $raw)) {
            $kv = $raw['kvlistValue'];
            if (!\is_array($kv) || !\is_array($kv['values'] ?? null)) {
                throw OtlpDecodeException::schemaMismatch("$path.kvlistValue.values must be an array.");
            }
            $items = [];
            foreach ($kv['values'] as $i => $entry) {
                $items[] = $this->decodeKeyValue($entry, "$path.kvlistValue.values[$i]");
            }

            return AnyValueDto::kvlist($items);
        }

        return new AnyValueDto();
    }

    private function int64($raw, string $path): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw && 1 === preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be an integer or numeric string.");
    }

    private function int32($raw, string $path): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw && 1 === preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be an integer.");
    }

    private function stringOrNull($raw, string $path): ?string
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be a string.");
        }

        return $raw;
    }

    /**
     * @return non-empty-string
     */
    private function hexBytes($raw, int $expectedLengthChars, string $path): string
    {
        if (!\is_string($raw) || '' === $raw) {
            throw OtlpDecodeException::schemaMismatch("$path is required.");
        }
        if (\strlen($raw) !== $expectedLengthChars || 1 !== preg_match('/^[0-9a-f]+$/', $raw)) {
            throw OtlpDecodeException::schemaMismatch(\sprintf(
                '%s must be exactly %d lowercase hex characters.',
                $path,
                $expectedLengthChars,
            ));
        }
        $bytes = hex2bin($raw);
        if (false === $bytes || '' === $bytes) {
            throw OtlpDecodeException::schemaMismatch("$path could not be decoded from hex.");
        }

        return $bytes;
    }

    private function hexBytesOrNull($raw, int $expectedLengthChars, string $path): ?string
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be a hex string.");
        }
        if (\strlen($raw) !== $expectedLengthChars || 1 !== preg_match('/^[0-9a-f]+$/', $raw)) {
            throw OtlpDecodeException::schemaMismatch(\sprintf(
                '%s must be exactly %d lowercase hex characters.',
                $path,
                $expectedLengthChars,
            ));
        }
        $bytes = hex2bin($raw);
        if (false === $bytes) {
            throw OtlpDecodeException::schemaMismatch("$path could not be decoded from hex.");
        }

        return $bytes;
    }
}
