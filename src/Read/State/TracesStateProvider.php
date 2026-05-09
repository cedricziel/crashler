<?php

declare(strict_types=1);

namespace App\Read\State;

use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\ColumnLikePrefix;
use App\Read\Compute\Predicates\ColumnLikeSuffix;
use App\Read\Resource\Trace;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * State provider for the Trace Resource's GetCollection operation.
 * Returns one row per matching span.
 */
final readonly class TracesStateProvider extends BaseSearchStateProvider
{
    private const array KIND_VALUES = ['UNSPECIFIED', 'INTERNAL', 'SERVER', 'CLIENT', 'PRODUCER', 'CONSUMER'];
    private const array STATUS_VALUES = ['UNSET', 'OK', 'ERROR'];

    protected function signalSubdir(): string
    {
        return 'traces';
    }

    protected function timeColumn(): string
    {
        return 'start_time_unix_nano';
    }

    protected function compilePerSignalPredicates(array $criteria): iterable
    {
        if (isset($criteria['name']) && \is_string($criteria['name']) && '' !== $criteria['name']) {
            $name = $criteria['name'];
            if (str_starts_with($name, '*')) {
                yield new ColumnLikeSuffix('name', substr($name, 1));
            } elseif (str_ends_with($name, '*')) {
                yield new ColumnLikePrefix('name', substr($name, 0, -1));
            } else {
                yield new ColumnEquals('name', $name);
            }
        }
        if (isset($criteria['kind']) && \is_string($criteria['kind']) && '' !== $criteria['kind']) {
            if (!\in_array($criteria['kind'], self::KIND_VALUES, true)) {
                throw new BadRequestException(\sprintf('`kind` must be one of: %s.', implode(', ', self::KIND_VALUES)));
            }
            yield new ColumnEquals('kind_text', $criteria['kind']);
        }
        if (isset($criteria['statusCode']) && \is_string($criteria['statusCode']) && '' !== $criteria['statusCode']) {
            if (!\in_array($criteria['statusCode'], self::STATUS_VALUES, true)) {
                throw new BadRequestException(\sprintf('`statusCode` must be one of: %s.', implode(', ', self::STATUS_VALUES)));
            }
            yield new ColumnEquals('status_text', $criteria['statusCode']);
        }
        if (isset($criteria['httpStatusCodeMin']) && '' !== $criteria['httpStatusCodeMin']) {
            yield new ColumnGreaterEqual('http_response_status_code', (int) $criteria['httpStatusCodeMin']);
        }
        if (isset($criteria['traceId']) && \is_string($criteria['traceId']) && '' !== $criteria['traceId']) {
            $hex = $criteria['traceId'];
            if (32 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{32}$/', $hex)) {
                throw new BadRequestException('`traceId` must be exactly 32 lowercase hex characters.');
            }
            yield new ColumnEquals('trace_id_hex', $hex);
        }
        if (isset($criteria['parentSpanId']) && \is_string($criteria['parentSpanId']) && '' !== $criteria['parentSpanId']) {
            $hex = $criteria['parentSpanId'];
            if (16 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{16}$/', $hex)) {
                throw new BadRequestException('`parentSpanId` must be exactly 16 lowercase hex characters.');
            }
            yield new ColumnEquals('parent_span_id_hex', $hex);
        }
    }

    public function rowToResource(array $row): Trace
    {
        return new Trace(
            traceIdHex: self::bytesToHex($row['trace_id_hex'] ?? null) ?? '',
            spanIdHex: self::bytesToHex($row['span_id_hex'] ?? null),
            parentSpanIdHex: self::bytesToHex($row['parent_span_id_hex'] ?? null),
            name: $row['name'] ?? '',
            kind: isset($row['kind']) ? (int) $row['kind'] : null,
            kindText: $row['kind_text'] ?? null,
            startTimeUnixNano: isset($row['start_time_unix_nano']) ? (string) $row['start_time_unix_nano'] : '',
            endTimeUnixNano: isset($row['end_time_unix_nano']) ? (string) $row['end_time_unix_nano'] : '',
            durationNano: isset($row['duration_nano']) ? (string) $row['duration_nano'] : null,
            statusCode: isset($row['status_code']) ? (int) $row['status_code'] : null,
            statusText: $row['status_text'] ?? null,
            statusMessage: $row['status_message'] ?? null,
            attributesJson: $row['attributes_json'] ?? '[]',
            eventsJson: $row['events_json'] ?? '[]',
            linksJson: $row['links_json'] ?? '[]',
            resourceServiceName: $row['resource_service_name'] ?? null,
            resourceDeploymentEnvironment: $row['resource_deployment_environment'] ?? null,
            resourceHostName: $row['resource_host_name'] ?? null,
            scopeName: $row['scope_name'] ?? null,
            scopeVersion: $row['scope_version'] ?? null,
            httpResponseStatusCode: isset($row['http_response_status_code']) ? (int) $row['http_response_status_code'] : null,
            resourceAttributesJson: $row['resource_attributes_json'] ?? '[]',
            schemaId: $row['_schema_id'] ?? 'traces/v1',
            schemaVersion: isset($row['_schema_version']) ? (int) $row['_schema_version'] : 1,
        );
    }
}
