<?php

declare(strict_types=1);

namespace App\Explorer;

final readonly class LogsProfile implements SignalProfile
{
    public function name(): string
    {
        return 'logs';
    }

    public function kpis(): array
    {
        return [
            new KpiSpec('total', 'Total', 'count', null, null),
            new KpiSpec('rate', 'Rate /min', 'count', null, '/min'),
            new KpiSpec('errors', 'Errors', 'count', null, null, errorIsBad: true),
            new KpiSpec('avg_severity', 'Avg severity', 'avg', 'severity_number'),
            new KpiSpec('uniq_traces', 'Unique traces', 'count', 'trace_id'),
        ];
    }

    public function filters(): array
    {
        return [
            new FilterDefinition('service', 'Service', FilterDefinition::KIND_TEXT),
            new FilterDefinition('environment', 'Environment', FilterDefinition::KIND_TEXT),
            new FilterDefinition('host', 'Host', FilterDefinition::KIND_TEXT),
            new FilterDefinition(
                'severity',
                'Severity',
                FilterDefinition::KIND_ENUM,
                ['TRACE', 'DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'],
            ),
            new FilterDefinition('traceId', 'Trace ID', FilterDefinition::KIND_TEXT),
        ];
    }

    public function tableColumns(): array
    {
        return [
            new TableColumn('time', 'Time', '14ch', monospace: true),
            new TableColumn('resource_service_name', 'Service', '12ch'),
            new TableColumn('severity_text', 'Severity', '8ch'),
            new TableColumn('trace_id', 'Trace', '10ch', monospace: true),
            new TableColumn('body', 'Body'),
        ];
    }

    public function defaultGroupBy(): string
    {
        return 'resource_service_name';
    }

    public function defaultColumn(): string
    {
        return 'severity_number';
    }

    public function rowClickRoute(): ?string
    {
        return null;
    }
}
