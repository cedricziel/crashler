<?php

declare(strict_types=1);

namespace App\Read\Controller;

/**
 * Reconstructs the OTLP `ResourceSpans → ScopeSpans → Span` tree from a
 * flat list of trace rows that came out of `ParquetScanner` (one row per
 * span). Groups by `(resource_attributes_json, scope_name, scope_version)`
 * to mirror how the write side stored them.
 */
final class TraceTreeAssembler
{
    /**
     * @param list<array<string, mixed>> $rows scanner rows for traces/v1
     *
     * @return list<array<string, mixed>> OTLP ResourceSpans-shaped array
     */
    public static function assemble(array $rows): array
    {
        $byResource = [];
        foreach ($rows as $row) {
            $resourceKey = $row['resource_attributes_json'] ?? '[]';
            $scopeKey = ($row['scope_name'] ?? '').'|'.($row['scope_version'] ?? '').'|'.($row['scope_schema_url'] ?? '');

            if (!isset($byResource[$resourceKey])) {
                $byResource[$resourceKey] = [
                    'resource' => self::buildResource($row),
                    'scopeBuckets' => [],
                ];
            }
            if (!isset($byResource[$resourceKey]['scopeBuckets'][$scopeKey])) {
                $byResource[$resourceKey]['scopeBuckets'][$scopeKey] = [
                    'scope' => self::buildScope($row),
                    'spans' => [],
                ];
            }

            $byResource[$resourceKey]['scopeBuckets'][$scopeKey]['spans'][] = self::rowToSpan($row);
        }

        $resourceSpans = [];
        foreach ($byResource as $bucket) {
            $scopeSpans = [];
            foreach ($bucket['scopeBuckets'] as $scopeBucket) {
                $scopeSpans[] = [
                    'scope' => $scopeBucket['scope'],
                    'spans' => $scopeBucket['spans'],
                ];
            }
            $resourceSpans[] = [
                'resource' => $bucket['resource'],
                'scopeSpans' => $scopeSpans,
            ];
        }

        return $resourceSpans;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{attributes: array<int, mixed>}
     */
    private static function buildResource(array $row): array
    {
        $raw = $row['resource_attributes_json'] ?? '[]';
        try {
            $attrs = \is_string($raw) ? json_decode($raw, true, flags: \JSON_THROW_ON_ERROR) : [];
        } catch (\JsonException) {
            $attrs = [];
        }

        return ['attributes' => \is_array($attrs) ? $attrs : []];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function buildScope(array $row): array
    {
        $scope = [];
        if (isset($row['scope_name']) && '' !== $row['scope_name']) {
            $scope['name'] = $row['scope_name'];
        }
        if (isset($row['scope_version']) && '' !== $row['scope_version']) {
            $scope['version'] = $row['scope_version'];
        }
        if (isset($row['scope_schema_url']) && '' !== $row['scope_schema_url']) {
            $scope['schemaUrl'] = $row['scope_schema_url'];
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function rowToSpan(array $row): array
    {
        $span = [
            'traceId' => self::bytesToHex($row['trace_id_hex'] ?? ''),
            'spanId' => self::bytesToHex($row['span_id_hex'] ?? ''),
            'name' => $row['name'] ?? '',
            'kind' => isset($row['kind']) ? (int) $row['kind'] : 0,
            'startTimeUnixNano' => isset($row['start_time_unix_nano']) ? (string) $row['start_time_unix_nano'] : '0',
            'endTimeUnixNano' => isset($row['end_time_unix_nano']) ? (string) $row['end_time_unix_nano'] : '0',
        ];

        if (isset($row['parent_span_id_hex']) && '' !== $row['parent_span_id_hex']) {
            $span['parentSpanId'] = self::bytesToHex($row['parent_span_id_hex']);
        }
        if (isset($row['trace_state']) && '' !== $row['trace_state']) {
            $span['traceState'] = $row['trace_state'];
        }
        if (isset($row['flags']) && 0 !== $row['flags']) {
            $span['flags'] = (int) $row['flags'];
        }

        // attributes_json is an OTLP-shaped attribute list; decode it.
        if (isset($row['attributes_json']) && '[]' !== $row['attributes_json']) {
            try {
                $attrs = json_decode($row['attributes_json'], true, flags: \JSON_THROW_ON_ERROR);
                if (\is_array($attrs)) {
                    $span['attributes'] = $attrs;
                }
            } catch (\JsonException) {
                // leave attributes off
            }
        }

        // events_json / links_json are JSON-string columns carrying their
        // own OTLP shape — pass through verbatim (decode then re-include).
        if (isset($row['events_json']) && '[]' !== $row['events_json']) {
            try {
                $events = json_decode($row['events_json'], true, flags: \JSON_THROW_ON_ERROR);
                if (\is_array($events) && [] !== $events) {
                    $span['events'] = $events;
                }
            } catch (\JsonException) {
            }
        }
        if (isset($row['links_json']) && '[]' !== $row['links_json']) {
            try {
                $links = json_decode($row['links_json'], true, flags: \JSON_THROW_ON_ERROR);
                if (\is_array($links) && [] !== $links) {
                    $span['links'] = $links;
                }
            } catch (\JsonException) {
            }
        }

        // status: only emit when at least one of the three fields is set.
        $status = [];
        if (isset($row['status_code']) && null !== $row['status_code']) {
            $status['code'] = (int) $row['status_code'];
        }
        if (isset($row['status_message']) && '' !== $row['status_message']) {
            $status['message'] = $row['status_message'];
        }
        if ([] !== $status) {
            $span['status'] = $status;
        }

        return $span;
    }

    private static function bytesToHex(?string $bytes): string
    {
        if (null === $bytes || '' === $bytes) {
            return '';
        }
        if (1 === preg_match('/^[0-9a-f]+$/', $bytes)) {
            return $bytes;
        }

        return bin2hex($bytes);
    }
}
