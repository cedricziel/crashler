<?php

declare(strict_types=1);

namespace App\Read\State;

use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\JsonAttributeEquals;
use App\Read\Resource\Metric;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

final readonly class MetricsStateProvider extends BaseSearchStateProvider
{
    private const array METRIC_TYPE_VALUES = ['SUM', 'GAUGE', 'HISTOGRAM', 'EXPONENTIAL_HISTOGRAM', 'SUMMARY'];
    private const array TEMPORALITY_VALUES = ['UNSPECIFIED', 'DELTA', 'CUMULATIVE'];

    protected function signalSubdir(): string
    {
        return 'metrics';
    }

    protected function compilePerSignalPredicates(array $criteria): iterable
    {
        if (isset($criteria['metricName']) && \is_string($criteria['metricName']) && '' !== $criteria['metricName']) {
            $name = $criteria['metricName'];
            if (str_contains($name, '*')) {
                throw new BadRequestException('`metricName` wildcards are not supported in v1; use exact match.');
            }
            yield new ColumnEquals('metric_name', $name);
        }
        if (isset($criteria['metricType']) && \is_string($criteria['metricType']) && '' !== $criteria['metricType']) {
            if (!\in_array($criteria['metricType'], self::METRIC_TYPE_VALUES, true)) {
                throw new BadRequestException(\sprintf('`metricType` must be one of: %s.', implode(', ', self::METRIC_TYPE_VALUES)));
            }
            yield new ColumnEquals('metric_type', $criteria['metricType']);
        }
        if (isset($criteria['aggregationTemporality']) && \is_string($criteria['aggregationTemporality']) && '' !== $criteria['aggregationTemporality']) {
            if (!\in_array($criteria['aggregationTemporality'], self::TEMPORALITY_VALUES, true)) {
                throw new BadRequestException(\sprintf('`aggregationTemporality` must be one of: %s.', implode(', ', self::TEMPORALITY_VALUES)));
            }
            yield new ColumnEquals('aggregation_temporality_text', $criteria['aggregationTemporality']);
        }
        if (isset($criteria['exemplarTraceId']) && \is_string($criteria['exemplarTraceId']) && '' !== $criteria['exemplarTraceId']) {
            $hex = $criteria['exemplarTraceId'];
            if (32 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{32}$/', $hex)) {
                throw new BadRequestException('`exemplarTraceId` must be exactly 32 lowercase hex characters.');
            }
            yield new JsonAttributeEquals('exemplars_json', 'traceId', $hex);
        }
    }

    protected function rowToResource(array $row): Metric
    {
        return new Metric(
            metricName: $row['metric_name'] ?? '',
            metricType: $row['metric_type'] ?? '',
            metricTypeCode: isset($row['metric_type_code']) ? (int) $row['metric_type_code'] : null,
            timeUnixNano: isset($row['time_unix_nano']) ? (string) $row['time_unix_nano'] : '',
            startTimeUnixNano: isset($row['start_time_unix_nano']) ? (string) $row['start_time_unix_nano'] : null,
            metricUnit: $row['metric_unit'] ?? null,
            metricDescription: $row['metric_description'] ?? null,
            aggregationTemporalityText: $row['aggregation_temporality_text'] ?? null,
            isMonotonic: isset($row['is_monotonic']) ? (bool) $row['is_monotonic'] : null,
            valueDouble: isset($row['value_double']) ? (float) $row['value_double'] : null,
            valueInt: isset($row['value_int']) ? (string) $row['value_int'] : null,
            count: isset($row['count']) ? (string) $row['count'] : null,
            sum: isset($row['sum']) ? (float) $row['sum'] : null,
            min: isset($row['min']) ? (float) $row['min'] : null,
            max: isset($row['max']) ? (float) $row['max'] : null,
            bucketsJson: $row['buckets_json'] ?? null,
            exponentialHistogramJson: $row['exponential_histogram_json'] ?? null,
            quantilesJson: $row['quantiles_json'] ?? null,
            exemplarsJson: $row['exemplars_json'] ?? '[]',
            attributesJson: $row['attributes_json'] ?? '[]',
            metricAttributesJson: $row['metric_attributes_json'] ?? '{}',
            resourceServiceName: $row['resource_service_name'] ?? null,
            resourceDeploymentEnvironment: $row['resource_deployment_environment'] ?? null,
            resourceHostName: $row['resource_host_name'] ?? null,
            scopeName: $row['scope_name'] ?? null,
            scopeVersion: $row['scope_version'] ?? null,
            resourceAttributesJson: $row['resource_attributes_json'] ?? '[]',
            schemaId: $row['_schema_id'] ?? 'metrics/v1',
            schemaVersion: isset($row['_schema_version']) ? (int) $row['_schema_version'] : 1,
        );
    }
}
