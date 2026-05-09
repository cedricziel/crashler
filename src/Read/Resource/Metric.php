<?php

declare(strict_types=1);

namespace App\Read\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Read\State\MetricsStateProvider;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/v1/metrics',
            shortName: 'Metric',
            paginationEnabled: true,
            paginationItemsPerPage: 100,
            paginationMaximumItemsPerPage: 1000,
            provider: MetricsStateProvider::class,
            parameters: [
                'since' => new QueryParameter(description: 'Time-window lower bound. RFC3339, unix-nano, or duration shorthand.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'since', in: 'query', example: '1h')),
                'until' => new QueryParameter(description: 'Time-window upper bound.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'until', in: 'query', example: '2026-05-09T15:00:00Z')),
                'service' => new QueryParameter(description: 'Filter by resource_service_name.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'service', in: 'query', example: 'checkout')),
                'environment' => new QueryParameter(description: 'Filter by resource_deployment_environment.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'environment', in: 'query', example: 'production')),
                'host' => new QueryParameter(description: 'Filter by resource_host_name.', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'host', in: 'query', example: 'app-001')),
                'metricName' => new QueryParameter(description: 'Filter by metric_name (exact match).', required: false, schema: ['type' => 'string'], openApi: new OpenApiParameter(name: 'metricName', in: 'query', example: 'http.server.request.duration')),
                'metricType' => new QueryParameter(description: 'Filter by metric_type (SUM|GAUGE|HISTOGRAM|EXPONENTIAL_HISTOGRAM|SUMMARY).', required: false, schema: ['type' => 'string', 'enum' => ['SUM', 'GAUGE', 'HISTOGRAM', 'EXPONENTIAL_HISTOGRAM', 'SUMMARY']], openApi: new OpenApiParameter(name: 'metricType', in: 'query', example: 'SUM')),
                'aggregationTemporality' => new QueryParameter(description: 'Filter by aggregation_temporality_text (UNSPECIFIED|DELTA|CUMULATIVE).', required: false, schema: ['type' => 'string', 'enum' => ['UNSPECIFIED', 'DELTA', 'CUMULATIVE']], openApi: new OpenApiParameter(name: 'aggregationTemporality', in: 'query', example: 'DELTA')),
                'exemplarTraceId' => new QueryParameter(description: 'Find rows whose exemplars carry the given traceId (32 lowercase hex chars).', required: false, schema: ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$'], openApi: new OpenApiParameter(name: 'exemplarTraceId', in: 'query', example: '5b8aa5a2d2c872e8321cf37308d69df2')),
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
final class Metric
{
    public function __construct(
        public string $metricName = '',
        public string $metricType = '',
        public ?int $metricTypeCode = null,
        public string $timeUnixNano = '',
        public ?string $startTimeUnixNano = null,
        public ?string $metricUnit = null,
        public ?string $metricDescription = null,
        public ?string $aggregationTemporalityText = null,
        public ?bool $isMonotonic = null,
        public ?float $valueDouble = null,
        public ?string $valueInt = null,
        public ?string $count = null,
        public ?float $sum = null,
        public ?float $min = null,
        public ?float $max = null,
        public ?string $bucketsJson = null,
        public ?string $exponentialHistogramJson = null,
        public ?string $quantilesJson = null,
        public string $exemplarsJson = '[]',
        public string $attributesJson = '[]',
        public string $metricAttributesJson = '{}',
        public ?string $resourceServiceName = null,
        public ?string $resourceDeploymentEnvironment = null,
        public ?string $resourceHostName = null,
        public ?string $scopeName = null,
        public ?string $scopeVersion = null,
        public string $resourceAttributesJson = '[]',
        public string $schemaId = 'metrics/v1',
        public ?int $schemaVersion = 1,
    ) {
    }
}
