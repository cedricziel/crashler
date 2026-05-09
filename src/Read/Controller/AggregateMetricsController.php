<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\Predicates\ColumnEquals;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AggregateMetricsController extends AggregateController
{
    #[Route(path: '/v1/metrics/aggregate', name: 'crashler_aggregate_metrics', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->handle($request);
    }

    protected function signalSubdir(): string
    {
        return 'metrics';
    }

    protected function allowedValueColumns(): array
    {
        return ['value_double', 'value_int', 'count', 'sum'];
    }

    protected function allowedGroupByColumns(): array
    {
        return ['resource_service_name', 'resource_deployment_environment', 'resource_host_name', 'metric_name', 'metric_type', 'aggregation_temporality_text'];
    }

    protected function valueColumnAliases(): array
    {
        return [
            'valueDouble' => 'value_double',
            'valueInt' => 'value_int',
        ];
    }

    protected function groupByAliases(): array
    {
        return [
            'service' => 'resource_service_name',
            'environment' => 'resource_deployment_environment',
            'host' => 'resource_host_name',
            'metricName' => 'metric_name',
            'metricType' => 'metric_type',
            'aggregationTemporality' => 'aggregation_temporality_text',
        ];
    }

    protected function buildSignalPredicates(Request $request): iterable
    {
        foreach (['service' => 'resource_service_name', 'environment' => 'resource_deployment_environment', 'host' => 'resource_host_name', 'metricName' => 'metric_name', 'metricType' => 'metric_type'] as $param => $column) {
            $value = $request->query->get($param);
            if (\is_string($value) && '' !== $value) {
                yield new ColumnEquals($column, $value);
            }
        }
    }
}
