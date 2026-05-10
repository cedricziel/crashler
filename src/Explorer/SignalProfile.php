<?php

declare(strict_types=1);

namespace App\Explorer;

/**
 * Per-signal facade that drives the explorer's variable surface area:
 * KPI definitions, available filter dimensions, table columns, and
 * sensible defaults for the aggregation controls.
 *
 * Three implementations live alongside this interface — one per signal.
 * The shell template branches on `name()`; the controller branches on the
 * profile, never on the signal string directly.
 */
interface SignalProfile
{
    /** 'logs' | 'traces' | 'metrics'. */
    public function name(): string;

    /** @return list<KpiSpec> exactly five entries */
    public function kpis(): array;

    /** @return list<FilterDefinition> */
    public function filters(): array;

    /** @return list<TableColumn> */
    public function tableColumns(): array;

    /** Default `groupBy` value for the chart series query. */
    public function defaultGroupBy(): string;

    /**
     * Default `column` for non-count aggregations. Returns `null` when the
     * signal has no obvious numeric column (in which case the user must
     * pick one explicitly).
     */
    public function defaultColumn(): ?string;

    /**
     * The Symfony route name to navigate to when the user clicks a row,
     * or `null` if rows aren't drillable. Today only the trace explorer
     * has a drill-to-waterfall route.
     */
    public function rowClickRoute(): ?string;
}
