<?php

declare(strict_types=1);

namespace App\Traces;

use App\Otlp\AnyValueJsonEncoder;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Contract\IngestsSignal;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Otlp\SpanEventJsonEncoder;
use App\Otlp\SpanLinkJsonEncoder;
use App\Storage\PartitionPathResolver;
use App\Storage\WritesParquetFiles;
use App\Tenancy\Tenant;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Flattens an OTLP ExportTraceServiceRequest into one row per Span and writes
 * a single Parquet file under the tenant's traces partition directory.
 *
 * Mirrors {@see \App\Logs\LogsIngestService}: resource attributes are
 * denormalized onto every row (the JSON blob keeps the full original list),
 * promoted columns are filled via {@see AttributeColumnExtractor}, and span
 * events/links are written as JSON-string columns so a future change can lift
 * them to first-class rows without losing data.
 */
final class TracesIngestService implements IngestsSignal
{
    private const SPAN_KIND_TEXT = [
        0 => 'UNSPECIFIED',
        1 => 'INTERNAL',
        2 => 'SERVER',
        3 => 'CLIENT',
        4 => 'PRODUCER',
        5 => 'CONSUMER',
    ];

    private const STATUS_TEXT = [
        0 => 'UNSET',
        1 => 'OK',
        2 => 'ERROR',
    ];

    public function __construct(
        private readonly WritesParquetFiles $writer,
        private readonly PartitionPathResolver $paths,
        private readonly AttributeColumnExtractor $extractor,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function write(object $request, Tenant $tenant): void
    {
        if (!$request instanceof ExportTraceServiceRequestDto) {
            throw new \TypeError(\sprintf(
                'TracesIngestService expects %s; got %s.',
                ExportTraceServiceRequestDto::class,
                $request::class,
            ));
        }

        $rows = iterator_to_array($this->toRows($request), false);
        if ([] === $rows) {
            return;
        }

        $paths = $this->paths->resolve($tenant, 'traces');

        try {
            $this->filesystem->mkdir($paths->partitionDir, 0o750);
        } catch (IOExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('Failed to create partition directory: %s', $paths->partitionDir), previous: $e);
        }

        $this->writer->writeAndCommit($paths->finalPath, $rows);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function toRows(ExportTraceServiceRequestDto $request): \Generator
    {
        foreach ($request->resourceSpans as $resource) {
            $resourcePromoted = $this->extractor->extractResource($resource->resourceAttributes);
            $resourceAttrsJson = AnyValueJsonEncoder::encodeAttributes($resource->resourceAttributes);

            foreach ($resource->scopeSpans as $scope) {
                // ScopeSpans.schema_url is a top-level field, not inside
                // Scope.attributes, so synthesise a KeyValueDto pair to feed
                // the extractor through the same uniform interface as logs.
                $scopeAttrs = [];
                if (null !== $scope->schemaUrl) {
                    $scopeAttrs[] = new KeyValueDto('schema_url', AnyValueDto::string($scope->schemaUrl));
                }
                $scopePromoted = $this->extractor->extractScope($scopeAttrs);

                foreach ($scope->spans as $span) {
                    $recordPromoted = $this->extractor->extractRecord($span->attributes);

                    yield self::spanToRow(
                        $span,
                        $scope,
                        $resourceAttrsJson,
                        $resourcePromoted,
                        $scopePromoted,
                        $recordPromoted,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, scalar|null> $resourcePromoted
     * @param array<string, scalar|null> $scopePromoted
     * @param array<string, scalar|null> $recordPromoted
     *
     * @return array<string, mixed>
     */
    private static function spanToRow(
        SpanDto $span,
        ScopeSpansDto $scope,
        string $resourceAttrsJson,
        array $resourcePromoted,
        array $scopePromoted,
        array $recordPromoted,
    ): array {
        $base = [
            'trace_id_hex' => bin2hex($span->traceId),
            'span_id_hex' => bin2hex($span->spanId),
            'name' => $span->name,
            'start_time_unix_nano' => $span->startTimeUnixNano,
            'end_time_unix_nano' => $span->endTimeUnixNano,
            'duration_nano' => $span->endTimeUnixNano - $span->startTimeUnixNano,
            'kind' => $span->kind,
            'kind_text' => self::SPAN_KIND_TEXT[$span->kind] ?? 'UNSPECIFIED',
            'parent_span_id_hex' => null !== $span->parentSpanId ? bin2hex($span->parentSpanId) : null,
            'trace_state' => $span->traceState,
            'flags' => $span->flags,
            'status_code' => $span->status?->code,
            'status_text' => null !== $span->status ? (self::STATUS_TEXT[$span->status->code] ?? null) : null,
            'status_message' => $span->status?->message,
            'dropped_attributes_count' => $span->droppedAttributesCount,
            'dropped_events_count' => $span->droppedEventsCount,
            'dropped_links_count' => $span->droppedLinksCount,
            'scope_name' => $scope->scopeName,
            'scope_version' => $scope->scopeVersion,
            'resource_attributes_json' => $resourceAttrsJson,
            'attributes_json' => AnyValueJsonEncoder::encodeAttributes($span->attributes),
            'events_json' => SpanEventJsonEncoder::encode($span->events),
            'links_json' => SpanLinkJsonEncoder::encode($span->links),
        ];

        return $base + $resourcePromoted + $scopePromoted + $recordPromoted;
    }
}
