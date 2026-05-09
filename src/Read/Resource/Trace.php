<?php

declare(strict_types=1);

namespace App\Read\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Read\State\TracesStateProvider;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/v1/traces',
            shortName: 'Trace',
            paginationEnabled: true,
            paginationItemsPerPage: 100,
            paginationMaximumItemsPerPage: 1000,
            provider: TracesStateProvider::class,
            parameters: [
                'since' => new QueryParameter(description: 'Time-window lower bound. RFC3339, unix-nano, or duration shorthand.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'since', in: 'query', example: '1h')),
                'until' => new QueryParameter(description: 'Time-window upper bound.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'until', in: 'query', example: '2026-05-09T15:00:00Z')),
                'service' => new QueryParameter(description: 'Filter by resource_service_name.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'service', in: 'query', example: 'checkout')),
                'environment' => new QueryParameter(description: 'Filter by resource_deployment_environment.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'environment', in: 'query', example: 'production')),
                'host' => new QueryParameter(description: 'Filter by resource_host_name.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'host', in: 'query', example: 'app-001')),
                'name' => new QueryParameter(description: 'Operation name; supports leading or trailing `*` wildcard.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'name', in: 'query', example: 'GET /orders/*')),
                'kind' => new QueryParameter(description: 'SpanKind text (UNSPECIFIED|INTERNAL|SERVER|CLIENT|PRODUCER|CONSUMER).', required: false, schema: ['type' => 'string', 'enum' => ['UNSPECIFIED', 'INTERNAL', 'SERVER', 'CLIENT', 'PRODUCER', 'CONSUMER']], openApi: new OpenApiParameter(name: 'kind', in: 'query', example: 'SERVER')),
                'statusCode' => new QueryParameter(description: 'SpanStatus text (UNSET|OK|ERROR).', required: false, schema: ['type' => 'string', 'enum' => ['UNSET', 'OK', 'ERROR']], openApi: new OpenApiParameter(name: 'statusCode', in: 'query', example: 'ERROR')),
                'httpStatusCodeMin' => new QueryParameter(description: 'Inclusive lower bound on http_response_status_code.', required: false, schema: ['type' => 'integer'], openApi: new OpenApiParameter(name: 'httpStatusCodeMin', in: 'query', example: 500)),
                'traceId' => new QueryParameter(description: 'Filter by trace_id_hex (32 lowercase hex chars).', required: false, schema: ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$'], openApi: new OpenApiParameter(name: 'traceId', in: 'query', example: '5b8aa5a2d2c872e8321cf37308d69df2')),
                'parentSpanId' => new QueryParameter(description: 'Filter by parent_span_id_hex (16 lowercase hex chars).', required: false, schema: ['type' => 'string', 'pattern' => '^[0-9a-f]{16}$'], openApi: new OpenApiParameter(name: 'parentSpanId', in: 'query', example: '051581bf3cb55c13')),
                'cursor' => new QueryParameter(description: 'Opaque pagination cursor.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'cursor', in: 'query', example: 'eyJjIjp7fX0.abc123')),
            ],
        ),
    ],
    formats: [
        'jsonld' => ['application/ld+json'],
        'jsonhal' => ['application/hal+json'],
        'json' => ['application/json'],
        'jsonapi' => ['application/vnd.api+json'],
    ],
)]
final class Trace
{
    public function __construct(
        public string $traceIdHex = '',
        public ?string $spanIdHex = null,
        public ?string $parentSpanIdHex = null,
        public string $name = '',
        public ?int $kind = null,
        public ?string $kindText = null,
        public string $startTimeUnixNano = '',
        public string $endTimeUnixNano = '',
        public ?string $durationNano = null,
        public ?int $statusCode = null,
        public ?string $statusText = null,
        public ?string $statusMessage = null,
        public string $attributesJson = '[]',
        public string $eventsJson = '[]',
        public string $linksJson = '[]',
        public ?string $resourceServiceName = null,
        public ?string $resourceDeploymentEnvironment = null,
        public ?string $resourceHostName = null,
        public ?string $scopeName = null,
        public ?string $scopeVersion = null,
        public ?int $httpResponseStatusCode = null,
        public string $resourceAttributesJson = '[]',
        public string $schemaId = 'traces/v1',
        public ?int $schemaVersion = 1,
    ) {
    }
}
