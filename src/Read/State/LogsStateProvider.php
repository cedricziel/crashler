<?php

declare(strict_types=1);

namespace App\Read\State;

use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\JsonStringContains;
use App\Read\Resource\Log;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

final readonly class LogsStateProvider extends BaseSearchStateProvider
{
    protected function signalSubdir(): string
    {
        return 'logs';
    }

    protected function compilePerSignalPredicates(array $criteria): iterable
    {
        if (isset($criteria['severityNumber']) && '' !== $criteria['severityNumber']) {
            yield new ColumnEquals('severity_number', (int) $criteria['severityNumber']);
        }
        if (isset($criteria['severityNumberMin']) && '' !== $criteria['severityNumberMin']) {
            yield new ColumnGreaterEqual('severity_number', (int) $criteria['severityNumberMin']);
        }
        if (isset($criteria['severityText']) && \is_string($criteria['severityText']) && '' !== $criteria['severityText']) {
            yield new ColumnEquals('severity_text', $criteria['severityText']);
        }
        if (isset($criteria['traceId']) && \is_string($criteria['traceId']) && '' !== $criteria['traceId']) {
            $hex = $criteria['traceId'];
            if (32 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{32}$/', $hex)) {
                throw new BadRequestException('`traceId` must be exactly 32 lowercase hex characters.');
            }
            yield new ColumnEquals('trace_id_hex', $hex);
        }
        if (isset($criteria['spanId']) && \is_string($criteria['spanId']) && '' !== $criteria['spanId']) {
            $hex = $criteria['spanId'];
            if (16 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{16}$/', $hex)) {
                throw new BadRequestException('`spanId` must be exactly 16 lowercase hex characters.');
            }
            yield new ColumnEquals('span_id_hex', $hex);
        }
        if (isset($criteria['eventName']) && \is_string($criteria['eventName']) && '' !== $criteria['eventName']) {
            yield new ColumnEquals('event_name', $criteria['eventName']);
        }
        if (isset($criteria['bodyContains']) && \is_string($criteria['bodyContains']) && '' !== $criteria['bodyContains']) {
            yield new JsonStringContains('body_json', $criteria['bodyContains']);
        }
    }

    protected function rowToResource(array $row): Log
    {
        return new Log(
            timeUnixNano: isset($row['time_unix_nano']) ? (string) $row['time_unix_nano'] : '',
            severityNumber: isset($row['severity_number']) ? (int) $row['severity_number'] : null,
            severityText: $row['severity_text'] ?? null,
            bodyJson: $row['body_json'] ?? null,
            attributesJson: $row['attributes_json'] ?? '[]',
            resourceServiceName: $row['resource_service_name'] ?? null,
            resourceDeploymentEnvironment: $row['resource_deployment_environment'] ?? null,
            resourceHostName: $row['resource_host_name'] ?? null,
            scopeName: $row['scope_name'] ?? null,
            scopeVersion: $row['scope_version'] ?? null,
            scopeSchemaUrl: $row['scope_schema_url'] ?? null,
            traceIdHex: isset($row['trace_id_hex']) ? self::bytesToHex($row['trace_id_hex']) : null,
            spanIdHex: isset($row['span_id_hex']) ? self::bytesToHex($row['span_id_hex']) : null,
            eventName: $row['event_name'] ?? null,
            resourceAttributesJson: $row['resource_attributes_json'] ?? '[]',
            schemaId: $row['_schema_id'] ?? 'logs/v1',
            schemaVersion: isset($row['_schema_version']) ? (int) $row['_schema_version'] : 1,
        );
    }
}
