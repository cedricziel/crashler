<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Criteria\TimeWindow;

/**
 * Loads every span for a given trace id and produces a depth-first flat
 * list ready to render as a waterfall: each entry carries its depth,
 * parent → child relationship is implicit in iteration order, and a
 * normalised duration window for sub-pixel-aware bar drawing.
 *
 * Spans without a known parent are roots; multiple roots may exist
 * (rare but possible). Children are sorted by start time so the
 * waterfall reads chronologically.
 *
 * The render is capped at MAX_SPANS — beyond that the lower-depth tail
 * is dropped and a "+N more" marker is exposed in the result so the
 * template can show the truncation honestly.
 */
final readonly class TraceWaterfallResolver
{
    public const int MAX_SPANS = 500;

    public function __construct(
        private ParquetScanner $scanner,
        private PartitionPruner $pruner,
        private int $spanLookupWindowHours = 24,
    ) {
    }

    /**
     * @return ?array{
     *     traceId: string,
     *     startNs: int,
     *     endNs: int,
     *     durationMs: float,
     *     rootName: string,
     *     service: string,
     *     spans: list<array<string, mixed>>,
     *     truncatedCount: int,
     * }
     */
    public function resolve(string $tenantSlug, string $traceId, TimeWindow $window): ?array
    {
        $globs = $this->pruner->globsFor($tenantSlug, 'traces', $window);
        $predicates = [new ColumnEquals('trace_id_hex', $traceId)];

        try {
            $result = $this->scanner->scan($globs, $predicates, limit: \PHP_INT_MAX);
        } catch (\Throwable) {
            return null;
        }

        if ([] === $result->rows) {
            return null;
        }

        // Sort by start time so root + children land in chronological
        // order before depth-first walk.
        $rowsByStart = $result->rows;
        usort($rowsByStart, static fn ($a, $b) => ($a['start_time_unix_nano'] ?? 0) <=> ($b['start_time_unix_nano'] ?? 0));

        // Build parent → children index.
        /** @var array<string, list<array<string, mixed>>> $byParent */
        $byParent = ['' => []];
        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        foreach ($rowsByStart as $row) {
            $spanId = (string) ($row['span_id_hex'] ?? '');
            $parentId = (string) ($row['parent_span_id_hex'] ?? '');
            $byId[$spanId] = $row;
            $byParent[$parentId][] = $row;
        }

        // Depth-first traversal from each root.
        $flat = [];
        $stack = [];
        foreach ($byParent[''] ?? [] as $root) {
            $stack[] = ['row' => $root, 'depth' => 0];
        }
        while ([] !== $stack) {
            $entry = array_pop($stack);
            $row = $entry['row'];
            $depth = $entry['depth'];

            if (\count($flat) >= self::MAX_SPANS) {
                break;
            }

            $flat[] = ['row' => $row, 'depth' => $depth];

            $spanId = (string) ($row['span_id_hex'] ?? '');
            $children = $byParent[$spanId] ?? [];
            // Push in reverse so the leftmost child pops first → DFS pre-order.
            for ($i = \count($children) - 1; $i >= 0; --$i) {
                $stack[] = ['row' => $children[$i], 'depth' => $depth + 1];
            }
        }

        $truncated = max(0, \count($rowsByStart) - \count($flat));

        // Trace's own time bounds across all rows we did walk.
        $startNs = (int) min(array_column($rowsByStart, 'start_time_unix_nano'));
        $endNs = (int) max(array_column($rowsByStart, 'end_time_unix_nano'));
        $durationMs = max(0.0, ($endNs - $startNs) / 1_000_000);

        // Pick a sensible "root" label even when multiple roots exist —
        // first by start time among those without a parent.
        $firstRoot = ($byParent[''][0] ?? null);
        $rootName = \is_array($firstRoot) ? (string) ($firstRoot['name'] ?? '') : '';
        $service = \is_array($firstRoot) ? (string) ($firstRoot['resource_service_name'] ?? '') : '';

        $spans = [];
        foreach ($flat as $entry) {
            $spans[] = $this->shapeSpan($entry['row'], $entry['depth'], $startNs, $endNs);
        }

        return [
            'traceId' => $traceId,
            'startNs' => $startNs,
            'endNs' => $endNs,
            'durationMs' => $durationMs,
            'rootName' => $rootName,
            'service' => $service,
            'spans' => $spans,
            'truncatedCount' => $truncated,
        ];
    }

    /**
     * Hydrate a single span by id, including the JSON-blob attributes/
     * events the waterfall sidebar needs. Returns null if the span isn't
     * found in this trace within the lookup window.
     *
     * @return ?array<string, mixed>
     */
    public function span(string $tenantSlug, string $traceId, string $spanId, TimeWindow $window): ?array
    {
        $globs = $this->pruner->globsFor($tenantSlug, 'traces', $window);
        $predicates = [
            new ColumnEquals('trace_id_hex', $traceId),
            new ColumnEquals('span_id_hex', $spanId),
        ];

        try {
            $result = $this->scanner->scan($globs, $predicates, limit: 1);
        } catch (\Throwable) {
            return null;
        }

        if ([] === $result->rows) {
            return null;
        }
        $row = $result->rows[0];

        $attrs = $this->decodeAttributes($row['attributes_json'] ?? null);
        $resourceAttrs = $this->decodeAttributes($row['resource_attributes_json'] ?? null);
        $events = $this->decodeJsonList($row['events_json'] ?? null);

        return [
            'spanId' => (string) ($row['span_id_hex'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'service' => (string) ($row['resource_service_name'] ?? ''),
            'startNs' => (int) ($row['start_time_unix_nano'] ?? 0),
            'endNs' => (int) ($row['end_time_unix_nano'] ?? 0),
            'durationMs' => max(0.0, ((int) ($row['end_time_unix_nano'] ?? 0) - (int) ($row['start_time_unix_nano'] ?? 0)) / 1_000_000),
            'statusCode' => isset($row['status_code']) ? (int) $row['status_code'] : null,
            'statusText' => (string) ($row['status_text'] ?? ''),
            'statusMessage' => (string) ($row['status_message'] ?? ''),
            'attributes' => $attrs,
            'resourceAttributes' => $resourceAttrs,
            'events' => $events,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function shapeSpan(array $row, int $depth, int $traceStartNs, int $traceEndNs): array
    {
        $startNs = (int) ($row['start_time_unix_nano'] ?? 0);
        $endNs = (int) ($row['end_time_unix_nano'] ?? 0);
        $width = $traceEndNs - $traceStartNs;

        // Normalised position [0..100] — bars with width < 0.5% snap up
        // so they're still clickable.
        $leftPct = $width > 0 ? max(0.0, min(100.0, ($startNs - $traceStartNs) * 100.0 / $width)) : 0.0;
        $widthPct = $width > 0 ? max(0.5, min(100.0 - $leftPct, ($endNs - $startNs) * 100.0 / $width)) : 100.0;

        return [
            'spanId' => (string) ($row['span_id_hex'] ?? ''),
            'parentSpanId' => (string) ($row['parent_span_id_hex'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'service' => (string) ($row['resource_service_name'] ?? ''),
            'depth' => $depth,
            'startNs' => $startNs,
            'endNs' => $endNs,
            'durationMs' => max(0.0, ($endNs - $startNs) / 1_000_000),
            'leftPct' => $leftPct,
            'widthPct' => $widthPct,
            'statusCode' => isset($row['status_code']) ? (int) $row['status_code'] : null,
            'statusText' => (string) ($row['status_text'] ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function decodeAttributes(mixed $raw): array
    {
        if (!\is_string($raw) || '[]' === $raw || '' === $raw) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }
        // OTLP attribute lists are arrays of {key, value: {<typed>: …}}.
        // Flatten to {key => stringified value} for sidebar render.
        $out = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry) || !isset($entry['key'])) {
                continue;
            }
            $key = (string) $entry['key'];
            $value = $entry['value'] ?? null;
            $out[$key] = $this->stringifyAnyValue($value);
        }

        return $out;
    }

    private function stringifyAnyValue(mixed $value): string
    {
        if (!\is_array($value)) {
            return (string) $value;
        }
        foreach (['stringValue', 'intValue', 'doubleValue', 'boolValue'] as $k) {
            if (isset($value[$k])) {
                return (string) $value[$k];
            }
        }
        // Fallback: JSON-encode the whole thing.
        try {
            return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return '';
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeJsonList(mixed $raw): array
    {
        if (!\is_string($raw) || '[]' === $raw || '' === $raw) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    public function spanLookupWindowHours(): int
    {
        return $this->spanLookupWindowHours;
    }
}
