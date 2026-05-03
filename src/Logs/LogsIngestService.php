<?php

declare(strict_types=1);

namespace App\Logs;

use App\Otlp\AnyValueJsonEncoder;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportLogsServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\LogRecordDto;
use App\Otlp\Dto\ResourceLogsDto;
use App\Otlp\Dto\ScopeLogsDto;
use App\Storage\PartitionPathResolver;
use App\Storage\WritesParquetFiles;
use App\Tenancy\Tenant;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Flattens an OTLP request into Parquet rows and writes a single file under
 * the tenant's partition directory. Resource attributes are denormalized onto
 * every row; service.name is hoisted into a dedicated column when present.
 */
final class LogsIngestService
{
    public function __construct(
        private readonly WritesParquetFiles $writer,
        private readonly PartitionPathResolver $paths,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function write(ExportLogsServiceRequestDto $request, Tenant $tenant): void
    {
        $rows = iterator_to_array($this->toRows($request), false);

        if ([] === $rows) {
            return;
        }

        $paths = $this->paths->resolve($tenant);

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
            $serviceName = self::extractServiceName($resource->resourceAttributes);
            $resourceAttrsJson = AnyValueJsonEncoder::encodeAttributes($resource->resourceAttributes);

            foreach ($resource->scopeLogs as $scope) {
                foreach ($scope->logRecords as $record) {
                    yield self::recordToRow($record, $scope, $serviceName, $resourceAttrsJson);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function recordToRow(
        LogRecordDto $record,
        ScopeLogsDto $scope,
        ?string $serviceName,
        string $resourceAttrsJson,
    ): array {
        return [
            'time_unix_nano' => $record->timeUnixNano,
            'observed_time_unix_nano' => $record->observedTimeUnixNano,
            'severity_number' => $record->severityNumber,
            'severity_text' => $record->severityText,
            'body_json' => null !== $record->body ? AnyValueJsonEncoder::encode($record->body) : null,
            'service_name' => $serviceName,
            'scope_name' => $scope->scopeName,
            'scope_version' => $scope->scopeVersion,
            'trace_id_hex' => null !== $record->traceId ? bin2hex($record->traceId) : null,
            'span_id_hex' => null !== $record->spanId ? bin2hex($record->spanId) : null,
            'flags' => $record->flags,
            'resource_attributes_json' => $resourceAttrsJson,
            'attributes_json' => AnyValueJsonEncoder::encodeAttributes($record->attributes),
        ];
    }

    /**
     * @param list<KeyValueDto> $resourceAttributes
     */
    private static function extractServiceName(array $resourceAttributes): ?string
    {
        foreach ($resourceAttributes as $kv) {
            if ('service.name' === $kv->key) {
                return self::asString($kv->value);
            }
        }

        return null;
    }

    private static function asString(AnyValueDto $value): ?string
    {
        return $value->stringValue;
    }
}
