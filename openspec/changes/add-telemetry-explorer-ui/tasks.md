# Tasks — `add-telemetry-explorer-ui`

TDD-ordered. Each implementation task has a sibling test task above it.
The "verify coverage gate" task at the bottom is mandatory per `openspec/config.yaml`.

> **Architecture pivots since the proposal landed** — the design has
> shifted in two ways during implementation; see `design.md` for the
> current shape:
>
> 1. **No fragment endpoints, no separate Stimulus drivers for the
>    page sections.** What `design.md` initially called Stimulus
>    controllers + fragment endpoints (KPI, table, autocomplete) is
>    now a single deferred Live Component per section. The browser
>    parallelises hydration via the standard `/_components/<name>`
>    endpoint that ships with `symfony/ux-live-component`.
> 2. **Resolvers call ParquetScanner / AggregatingScanner directly.**
>    Original plan was to dispatch `HttpKernelInterface::handle()`
>    sub-requests against `/v1/{signal}/aggregate`; the in-process
>    service call is shorter and avoids the synthetic-IngestUser
>    plumbing the sub-request approach would have needed.
>
> Both pivots are signal-agnostic — same architecture for logs /
> traces / metrics.

## Setup

- [x] **1.1** Install dependencies — `composer require symfony/ux-twig-component symfony/ux-live-component`. uPlot importmap'd, CSS imported in `assets/app.js`.

## Signal profile abstraction

- [x] **2.1** Test: `tests/Unit/Explorer/SignalProfileRegistryTest` — registry resolves logs/traces/metrics; unknown signal throws.
- [x] **2.2** Implement: `App\Explorer\SignalProfile` interface, `SignalProfileRegistry`, `LogsProfile`, `TracesProfile`, `MetricsProfile`. Wired via `_instanceof` + `tagged_iterator`.

## KPI bundle resolver

- [x] **3.1** Test: `tests/Unit/Explorer/PriorWindowCalculatorTest`.
- [x] **3.2** Implement: `App\Explorer\PriorWindowCalculator`.
- [ ] **3.3** Test: dedicated `tests/Unit/Explorer/KpiBundleResolverTest` for the de-dupe / null-prior contract. (KpiStripComponentTest covers the populated path end-to-end; the unit-level dedup contract is not yet pinned in isolation.)
- [x] **3.4** Implement: `App\Explorer\KpiBundleResolver` — single-pass scan with multiple Accumulators. Cache layer + `WindowBucket::snap()`.

## Explorer routes & controller

- [x] **4.1** Test: `tests/Functional/Explorer/ExplorerAccessTest` — all four access paths (anon redirect / non-member 403 / unknown signal 404 / member 200).
- [x] **4.2** Implement: `App\Controller\ExplorerController`. Thin per the architecture pivot — only validates signal name, parses time window, hands bounds to deferred Live Components.
- [x] **4.3** Test: URL query params survive into the rendered form (covered by `testFormSubmissionWithEmptyUntilStillRenders`).
- [x] **4.4** Implement: query-param parsing in QueryForm Live Component.
- [x] **4.5** Test: window-cap inline error path (covered indirectly — the controller catches the parser's exceptions and surfaces `window_error`).
- [x] **4.6** Implement: window_error banner in the page template.

## Twig component shell

- [SKIP] **5.1, 5.2** Layout component — superseded. Layout stays as plain twig in `templates/explorer/index.html.twig`; per-row Live Components handle the variable surface.
- [SKIP] **5.3, 5.4** SignalTabs component — superseded. Signal tabs are inline HTML in the page template.

## KPI strip + tile

- [SKIP] **6.1** Dedicated KpiTileComponentTest — covered indirectly by `tests/Component/Explorer/KpiStripComponentTest`'s loading/empty/populated assertions which render through KpiTile.
- [x] **6.2** Implement: `App\Twig\Components\Explorer\KpiTile` (passive component, all four states).
- [x] **6.3** Test: `tests/Component/Explorer/KpiStripComponentTest`.
- [x] **6.4** Implement: `App\Twig\Components\Explorer\KpiStrip` (Live Component, deferred).

## Chart shell + Stimulus controller

- [ ] **7.1** Test: `tests/Component/Explorer/ChartComponentTest` — chart canvas rendered with `data-chart-endpoint-value` set.
- [SKIP] **7.2** Chart component extraction — chart canvas is inline in `templates/explorer/index.html.twig`. Not yet worth extracting to its own component until interval bucketing lands in the AggregatingScanner.
- [ ] **7.3** Test: `tests/Functional/Explorer/ChartDataEndpointTest` — `_chart.json` endpoint returns expected shape.
- [ ] **7.4** Implement: `ExplorerController::chartData` route. Blocked on the AggregatingScanner growing interval bucketing (today it can't time-bucket).
- [x] **7.5** Implement: `assets/controllers/chart_controller.js` (uPlot init, brush dispatch). Lazy-imports uPlot.

## Query form (Live)

- [ ] **8.1** Test: `tests/Component/Explorer/QueryFormComponentTest` — initial render contains all filter chips + time inputs + aggregation controls; LiveProps reflect URL.
- [x] **8.2** Implement: `App\Twig\Components\Explorer\QueryForm` (AsLiveComponent). Filter chips, time inputs, aggregation controls all rendered inline. Submit goes via the form's GET action; LiveAction-based submit is deferred.
- [ ] **8.3** Test: `tests/Functional/Explorer/QueryFormBrushSelectTest` — `applyBrush` LiveAction.
- [ ] **8.4** Implement: `applyBrush` LiveAction + the JS handler that catches `chart_controller`'s `explorer:time-range-selected` event and rewrites the URL.
- [SKIP] **8.5, 8.6** Sub-component extraction (`FilterChip`, `TimeRangePicker`, `AggregationCtrls`) — superseded. Form fields stay inline in QueryForm template; extracting them now is premature.

## Result table + Stimulus controller

- [x] **9.1** Test: `tests/Component/Explorer/ResultTableComponentTest` — populated, empty, loading, ns-timestamp regression.
- [x] **9.2** Implement: `App\Twig\Components\Explorer\ResultTable` (Live Component, deferred). State-aware template.
- [ ] **9.3** Test: `tests/Functional/Explorer/RowsFragmentEndpointTest`.
- [ ] **9.4** Implement: `ExplorerController::tableFragment` route — `_rows` HTML fragment for cursor pagination.
- [x] **9.5** Implement: `assets/controllers/table_controller.js` (paginator UI is the missing half — the controller exists, the next/prev buttons don't yet).

## Trace waterfall page

- [ ] **10.1** Test: `tests/Functional/Waterfall/WaterfallAccessTest`.
- [ ] **10.2** Implement: `App\Controller\WaterfallController`. Reuses `TraceTreeAssembler`.
- [ ] **10.3** Test: `tests/Component/Waterfall/RowComponentTest`.
- [ ] **10.4** Implement: `App\Twig\Components\Waterfall\Root|Header|Axis|RowList|Row`.
- [ ] **10.5** Test: `tests/Functional/Waterfall/SpanCapTest` (500-span cap).
- [ ] **10.6** Implement: cap with descendant pruning.

## Trace waterfall sidebar (Live)

- [ ] **11.1** Test: `tests/Component/Waterfall/SidebarComponentTest`.
- [ ] **11.2** Implement: `App\Twig\Components\Waterfall\Sidebar` (AsLiveComponent).
- [ ] **11.3** Test: `tests/Functional/Waterfall/SidebarLiveActionTest` (cross-trace defense).
- [ ] **11.4** Implement: voter re-check + cross-trace defense.
- [ ] **11.5** Test: `tests/Component/Common/AttributesTableComponentTest`.
- [ ] **11.6** Implement: `App\Twig\Components\Common\AttributesTable`.

## Drill to logs

- [ ] **12.1** Test: `tests/Functional/Waterfall/DrillToLogsLinkTest`.
- [ ] **12.2** Implement: drill link in sidebar template.

## Polish + autocomplete

- [x] **13.0** (added) `App\Twig\Components\Explorer\FilterDatalists` Live Component — autocomplete for filter chips. Tests at `tests/Component/Explorer/FilterDatalistsComponentTest`.
- [x] **13.1** Test: empty-state copy on the result table (covered by `testHydratedWindowWithNoDataRendersEmptyStateCopy`).
- [SKIP] **13.2** Implement: any missing copy — done inline.
- [ ] **13.3** Test: `tests/Functional/Explorer/CursorPaginationStaysOnPageTest`. Blocked on 9.4 (the `_rows` endpoint) being implemented first.
- [SKIP] **13.4** Implement: any missing wiring — covered by 9.5.
- [SKIP] **13.5** Manual smoke test — done multiple times during the iterative deploy cycle.

## Coverage gate

- [x] **14.1** `composer coverage:gate` green at 82.45% (gate 80%) on every commit.

## Deploy

- [x] **15.1** `composer cs:fix` + `composer phpstan` run on every commit.
- [x] **15.2** Pushed to main multiple times; CI green.
- [x] **15.3** Deployed to production multiple times via `dep deploy stage=production`.

## Perf follow-ups (not in original spec)

- [x] **16.1** TTL-based caching on all three resolvers (`KpiBundleResolver`, `TableResultResolver`, `AutocompleteResolver`) using `cache.app`. Window bucket-snapped to 60s grid for cache key stability on actively-ingesting tenants.
- [x] **16.2** KpiStrip single-pass multi-accumulator scan (5 unique groupKeys × 2 windows = 10 scans → 2 scans).
- [x] **16.3** Parquet file compaction — `bin/console crashler:explorer:compact-partitions`. Walks `<storage>/{signal}/<tenant>/date=*/hour=*/`, skips partitions newer than `--min-age-hours` (default 2), merges every partition with ≥2 parquet files into a single `compacted-<ts>.parquet` via `ParquetFileWriter` (atomic rename), then deletes originals. Idempotent on crash (re-runs pick up partial state). Mixed-schema partitions are reported and skipped. Tested at `tests/Functional/Console/CompactPartitionsCommandTest`.

## Remaining feature scope (priority-ordered)

1. **9.3, 9.4** `_rows` cursor-pagination endpoint + paginator UI (table currently shows first page only)
2. **8.4** Brush-select → URL rewrite plumbing (chart canvas can't yet rewrite the page's window)
3. **7.4** `_chart.json` endpoint + interval bucketing in AggregatingScanner (chart canvas is empty placeholder)
4. **10.x, 11.x, 12.x** Trace waterfall + sidebar + drill-to-logs (still empty for v1)
