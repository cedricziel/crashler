<?php

declare(strict_types=1);

namespace App\Read\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Read\State\LogsStateProvider;

/**
 * Log Resource — one record per Parquet row from `logs/v1`.
 *
 * Properties mirror the on-disk column names in camelCase (D10). The
 * collection's State Provider is {@see LogsStateProvider} which translates
 * URL parameters into typed predicates and dispatches to the streaming
 * Parquet scanner.
 *
 * Logs have no stable per-record URI (no Item operation). Every Log is
 * reached via search; cross-signal navigation comes from per-row `_links`
 * carrying `trace` and `span` rels.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/v1/logs',
            shortName: 'Log',
            paginationEnabled: true,
            paginationItemsPerPage: 100,
            paginationMaximumItemsPerPage: 1000,
            provider: LogsStateProvider::class,
            parameters: [
                'since' => new QueryParameter(
                    description: 'Lower bound of the time window. RFC3339 timestamp, unix-nano numeric string, or duration shorthand (30m / 2h / 7d). When omitted, defaults to 1 hour ago.',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'until' => new QueryParameter(
                    description: 'Upper bound of the time window. RFC3339 timestamp or unix-nano numeric string. When omitted, defaults to now.',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'service' => new QueryParameter(
                    description: 'Filter by resource_service_name (exact match).',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'environment' => new QueryParameter(
                    description: 'Filter by resource_deployment_environment (exact match).',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'host' => new QueryParameter(
                    description: 'Filter by resource_host_name (exact match).',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'severityNumber' => new QueryParameter(
                    description: 'Filter by exact severity number (1-24 per OTLP).',
                    required: false,
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 24],
                ),
                'severityNumberMin' => new QueryParameter(
                    description: 'Inclusive lower bound on severity number (>=).',
                    required: false,
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 24],
                ),
                'severityText' => new QueryParameter(
                    description: 'Filter by severity_text (exact match).',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'traceId' => new QueryParameter(
                    description: 'Filter by trace_id_hex (32 lowercase hex chars).',
                    required: false,
                    schema: ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$'],
                ),
                'spanId' => new QueryParameter(
                    description: 'Filter by span_id_hex (16 lowercase hex chars).',
                    required: false,
                    schema: ['type' => 'string', 'pattern' => '^[0-9a-f]{16}$'],
                ),
                'eventName' => new QueryParameter(
                    description: 'Filter by event_name (exact match).',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'bodyContains' => new QueryParameter(
                    description: 'Substring match against body_json. No row-group push-down — combine with a service or time filter for performance.',
                    required: false,
                    schema: ['type' => 'string'],
                ),
                'cursor' => new QueryParameter(
                    description: 'Opaque pagination cursor returned by a previous response. When supplied, all other filter parameters are ignored.',
                    required: false,
                    schema: ['type' => 'string'],
                ),
            ],
        ),
    ],
    formats: [
        'jsonld' => ['application/ld+json'],
        'hal' => ['application/hal+json'],
        'json' => ['application/json'],
        'jsonapi' => ['application/vnd.api+json'],
    ],
)]
final class Log
{
    public function __construct(
        public string $timeUnixNano = '',
        public ?int $severityNumber = null,
        public ?string $severityText = null,
        public ?string $bodyJson = null,
        public string $attributesJson = '[]',
        public ?string $resourceServiceName = null,
        public ?string $resourceDeploymentEnvironment = null,
        public ?string $resourceHostName = null,
        public ?string $scopeName = null,
        public ?string $scopeVersion = null,
        public ?string $scopeSchemaUrl = null,
        public ?string $traceIdHex = null,
        public ?string $spanIdHex = null,
        public ?string $eventName = null,
        public string $resourceAttributesJson = '[]',
        public string $schemaId = 'logs/v1',
        public ?int $schemaVersion = 1,
    ) {
    }
}
