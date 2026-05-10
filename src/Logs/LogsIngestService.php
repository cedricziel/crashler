<?php

declare(strict_types=1);

namespace App\Logs;

use App\Otlp\AnyValueJsonEncoder;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Contract\IngestsSignal;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Storage\PartitionPathResolver;
use App\Storage\WritesParquetFiles;
use App\Tenancy\Tenant;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Flattens an OTLP request into Parquet rows and writes a single file under
 * the tenant's partition directory.
 *
 * Resource attributes are denormalized onto every row (the JSON blob carries
 * the full original list verbatim). Promoted columns — defined in the logs
 * schema YAML's `promotions` block — are filled via {@see AttributeColumnExtractor}
 * so renaming or adding a column is a YAML edit, not a code change.
 */
final class LogsIngestService implements IngestsSignal
{
    public function __construct(
        private readonly WritesParquetFiles $writer,
        private readonly PartitionPathResolver $paths,
        private readonly AttributeColumnExtractor $extractor,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function write(object $request, Tenant $tenant): void
    {
        if (!$request instanceof ExportLogsServiceRequestDto) {
            throw new \TypeError(\sprintf(
                'LogsIngestService expects %s; got %s.',
                ExportLogsServiceRequestDto::class,
                $request::class,
            ));
        }

        $rows = iterator_to_array($this->toRows($request), false);

        if ([] === $rows) {
            return;
        }

        $paths = $this->paths->resolve($tenant, 'logs');

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
    private function toRows(ExportLogsServiceRequestDto $request): \Generator
    {
        foreach ($request->resourceLogs as $resource) {
            $resourcePromoted = $this->extractor->extractResource($resource->resourceAttributes);
            $resourceAttrsJson = AnyValueJsonEncoder::encodeAttributes($resource->resourceAttributes);

            foreach ($resource->scopeLogs as $scope) {
                // Scope-level attributes that the schema can promote: schema_url
                // is a top-level ScopeLogs field, not inside Scope.attributes,
                // so we synthesise a KeyValueDto pair to feed the extractor
                // through a uniform interface.
                $scopeAttrs = [];
                if (null !== $scope->schemaUrl) {
                    $scopeAttrs[] = new KeyValueDto('schema_url', AnyValueDto::string($scope->schemaUrl));
                }
                $scopePromoted = $this->extractor->extractScope($scopeAttrs);

                foreach ($scope->logRecords as $record) {
                    $recordPromoted = $this->extractor->extractRecord($record->attributes);

                    yield self::recordToRow(
                        $record,
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
    private static function recordToRow(
        LogRecordDto $record,
        ScopeLogsDto $scope,
        string $resourceAttrsJson,
        array $resourcePromoted,
        array $scopePromoted,
        array $recordPromoted,
    ): array {
        $base = [
            'time_unix_nano' => $record->timeUnixNano,
            'observed_time_unix_nano' => $record->observedTimeUnixNano,
            'severity_number' => $record->severityNumber,
            'severity_text' => $record->severityText,
            'body_json' => null !== $record->body ? AnyValueJsonEncoder::encode($record->body) : null,
            'scope_name' => $scope->scopeName,
            'scope_version' => $scope->scopeVersion,
            'trace_id_hex' => null !== $record->traceId ? bin2hex($record->traceId) : null,
            'span_id_hex' => null !== $record->spanId ? bin2hex($record->spanId) : null,
            'flags' => $record->flags,
            'resource_attributes_json' => $resourceAttrsJson,
            'attributes_json' => AnyValueJsonEncoder::encodeAttributes($record->attributes),
        ];

        // Promoted columns are merged in. They never clash with the base map's
        // keys because the schema validator rejects '_schema_*' (writer-emitted)
        // names and the base map is hand-curated from non-promoted fields.
        return $base + $resourcePromoted + $scopePromoted + $recordPromoted;
    }
}
