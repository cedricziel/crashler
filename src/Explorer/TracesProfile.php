<?php

declare(strict_types=1);

namespace App\Explorer;

final readonly class TracesProfile implements SignalProfile
{
    public function name(): string
    {
        return 'traces';
    }

    public function kpis(): array
    {
        return [
            new KpiSpec('total', 'Spans', 'count'),
            new KpiSpec('avg_dur', 'Avg dur', 'avg', 'duration_ns', 'ms'),
            new KpiSpec('p95_dur', 'p95 dur', 'max', 'duration_ns', 'ms'),
            new KpiSpec('errors', 'Errors', 'count', 'status_code', null, errorIsBad: true),
            new KpiSpec('uniq_routes', 'Routes', 'count', 'span_name'),
        ];
    }

    public function filters(): array
    {
        return [
            new FilterDefinition('service', 'Service', FilterDefinition::KIND_TEXT, parquetColumn: 'resource_service_name'),
            new FilterDefinition('environment', 'Environment', FilterDefinition::KIND_TEXT, parquetColumn: 'resource_deployment_environment'),
            new FilterDefinition('span_name', 'Span name', FilterDefinition::KIND_TEXT, parquetColumn: 'span_name'),
            new FilterDefinition(
                'status',
                'Status',
                FilterDefinition::KIND_ENUM,
                ['UNSET', 'OK', 'ERROR'],
            ),
            // trace_id is per-request unique — autocomplete would be useless.
            new FilterDefinition('trace_id', 'Trace ID', FilterDefinition::KIND_TEXT),
        ];
    }

    public function tableColumns(): array
    {
        return [
            new TableColumn('time', 'Time', '14ch', monospace: true),
            new TableColumn('resource_service_name', 'Service', '12ch'),
            new TableColumn('span_name', 'Root span'),
            new TableColumn('status_code_text', 'Status', '8ch'),
            new TableColumn('duration_ns', 'Duration', '10ch', monospace: true),
        ];
    }

    public function defaultGroupBy(): string
    {
        return 'span_name';
    }

    public function defaultColumn(): string
    {
        return 'duration_ns';
    }

    public function rowClickRoute(): string
    {
        return 'app_trace_waterfall';
    }
}
