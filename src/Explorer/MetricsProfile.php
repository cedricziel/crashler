<?php

declare(strict_types=1);

namespace App\Explorer;

final readonly class MetricsProfile implements SignalProfile
{
    public function name(): string
    {
        return 'metrics';
    }

    public function kpis(): array
    {
        return [
            new KpiSpec('series', 'Series', 'count', 'metric_name'),
            new KpiSpec('datapoints', 'Datapoints /min', 'count', null, '/min'),
            new KpiSpec('sum', 'Sum', 'sum', 'value_double'),
            new KpiSpec('p95', 'p95', 'max', 'value_double'),
            new KpiSpec('uniq_metrics', 'Metrics', 'count', 'metric_name'),
        ];
    }

    public function filters(): array
    {
        return [
            new FilterDefinition('service', 'Service', FilterDefinition::KIND_TEXT, parquetColumn: 'resource_service_name'),
            new FilterDefinition('environment', 'Environment', FilterDefinition::KIND_TEXT, parquetColumn: 'resource_deployment_environment'),
            new FilterDefinition('metric_name', 'Metric', FilterDefinition::KIND_TEXT, parquetColumn: 'metric_name'),
            new FilterDefinition(
                'metric_type',
                'Type',
                FilterDefinition::KIND_ENUM,
                ['gauge', 'sum', 'histogram', 'exponential_histogram'],
            ),
        ];
    }

    public function tableColumns(): array
    {
        return [
            new TableColumn('time', 'Time', '14ch', monospace: true),
            new TableColumn('metric_name', 'Metric'),
            new TableColumn('metric_type', 'Type', '8ch'),
            new TableColumn('resource_service_name', 'Service', '12ch'),
            new TableColumn('value_double', 'Value', '10ch', monospace: true),
        ];
    }

    public function defaultGroupBy(): string
    {
        return 'metric_name';
    }

    public function defaultColumn(): string
    {
        return 'value_double';
    }

    public function rowClickRoute(): ?string
    {
        return null;
    }
}
