# Tasks — `add-telemetry-explorer-ui`

TDD-ordered. Each implementation task has a sibling test task above it.
The "verify coverage gate" task at the bottom is mandatory per `openspec/config.yaml`.

## Setup

- [ ] **1.1** Install dependencies — `composer require symfony/ux-twig-component symfony/ux-live-component`. Add uPlot to AssetMapper importmap (`bin/console importmap:require uplot`). Update `assets/app.js` to import uPlot's CSS.

## Signal profile abstraction

- [ ] **2.1** Test: `tests/Unit/Explorer/SignalProfileRegistryTest` — registry resolves `logs`, `traces`, `metrics` to their profile classes; unknown signal throws.
- [ ] **2.2** Implement: `App\Explorer\SignalProfile` interface, `SignalProfileRegistry`, three concrete profiles `LogsProfile`, `TracesProfile`, `MetricsProfile`. Each profile declares `kpis()`, `filters()`, `tableColumns()`, `defaultGroupBy()`, `defaultColumn()`, `rowClickRoute()`. Wire into the service container with `!tagged_iterator` for the registry.

## KPI bundle resolver

- [ ] **3.1** Test: `tests/Unit/Explorer/PriorWindowCalculatorTest` — `current=[t0,t1]` yields `prior=[t0-(t1-t0), t0]`.
- [ ] **3.2** Implement: `App\Explorer\PriorWindowCalculator`.
- [ ] **3.3** Test: `tests/Unit/Explorer/KpiBundleResolverTest` — KPIs sharing `function`/`column`/filter signature de-dupe to one aggregate call per window. KPIs with no comparable prior data return delta=null.
- [ ] **3.4** Implement: `App\Explorer\KpiBundleResolver` (uses `HttpKernelInterface` sub-requests to `/v1/{signal}/aggregate`).

## Explorer routes & controller

- [ ] **4.1** Test: `tests/Functional/Explorer/ExplorerAccessTest` — anonymous → 302 login, non-member → 403, unknown signal → 404, member → 200.
- [ ] **4.2** Implement: `App\Controller\ExplorerController` with route `GET /tenants/{slug}/explore/{signal}`. Resolves the `Tenant` entity, denies-unless-granted `READ` via `TenantVoter`, picks the right `SignalProfile`, runs the KPI bundle + first-page search + chart aggregate, renders `templates/explorer/index.html.twig`.
- [ ] **4.3** Test: `tests/Functional/Explorer/ExplorerUrlSourceTest` — query params on the URL are reflected in the rendered form fields and the actual queries dispatched.
- [ ] **4.4** Implement: query-param parsing; pass into the SignalProfile's filter/aggregation contracts.
- [ ] **4.5** Test: `tests/Functional/Explorer/ExplorerTimeWindowCapTest` — submitting a window > `max_time_window_days` re-renders with inline error banner naming the cap.
- [ ] **4.6** Implement: catch the existing Read API's 400 and render a friendly inline error.

## Twig component shell — passive

- [ ] **5.1** Test: `tests/Component/Explorer/LayoutExplorerComponentTest` — renders the 5-row scaffold, signal-tab nav, page heading.
- [ ] **5.2** Implement: `App\Twig\Components\Explorer\Layout` + template `templates/components/explorer/layout.html.twig`.
- [ ] **5.3** Test: `tests/Component/Explorer/SignalTabsComponentTest` — current tab is marked active, others are links.
- [ ] **5.4** Implement: `App\Twig\Components\Explorer\SignalTabs`.

## KPI strip + tile (passive, all four states)

- [ ] **6.1** Test: `tests/Component/Explorer/KpiTileComponentTest` — four cases: loading (skeleton class), empty (em-dash), error (`?` + tooltip), populated (number + delta arrow + percent).
- [ ] **6.2** Implement: `App\Twig\Components\Explorer\KpiTile` + state-aware template.
- [ ] **6.3** Test: `tests/Component/Explorer/KpiStripComponentTest` — five tiles in a row.
- [ ] **6.4** Implement: `App\Twig\Components\Explorer\KpiStrip`.

## Chart shell + Stimulus controller

- [ ] **7.1** Test: `tests/Component/Explorer/ChartComponentTest` — renders an empty `<canvas data-controller="chart" data-chart-endpoint-value="…" data-chart-empty-message-value="…">`.
- [ ] **7.2** Implement: `App\Twig\Components\Explorer\Chart` + template (canvas + skeleton bars overlay shown until JS hydrates).
- [ ] **7.3** Test: `tests/Functional/Explorer/ChartDataEndpointTest` — `GET /tenants/{slug}/explore/{signal}/_chart.json` returns shape `{series: [...], xMin, xMax, yMin, yMax}`.
- [ ] **7.4** Implement: `ExplorerController::chartData` route.
- [ ] **7.5** Implement: `assets/controllers/chart_controller.js` — fetches the endpoint, instantiates uPlot, listens for `setSelect` and dispatches `explorer:time-range-selected`.

## Query form (Live)

- [ ] **8.1** Test: `tests/Component/Explorer/QueryFormComponentTest` — initial render contains all filter chips, time inputs, aggregation controls. LiveProps reflect the URL query.
- [ ] **8.2** Implement: `App\Twig\Components\Explorer\QueryForm` (AsLiveComponent) with LiveProps for `filters: array`, `since`, `until`, `function`, `column`, `groupBy`, `interval`. LiveAction `addFilter`, `removeFilter`, `submit`. Template includes `<twig:Explorer:FilterChip>`, `<twig:Explorer:TimeRangePicker>`, `<twig:Explorer:AggregationCtrls>`.
- [ ] **8.3** Test: `tests/Functional/Explorer/QueryFormBrushSelectTest` — invoking the LiveAction `applyBrush` with `since/until` triggers a redirect with new query params.
- [ ] **8.4** Implement: `applyBrush` LiveAction. Wire chart_controller's `explorer:time-range-selected` event into the form via JS hook.
- [ ] **8.5** Test: `tests/Component/Explorer/AggregationCtrlsComponentTest` — `column` field is disabled when `function=count`.
- [ ] **8.6** Implement: child Live components `FilterChip`, `TimeRangePicker`, `AggregationCtrls`.

## Result table + Stimulus controller

- [ ] **9.1** Test: `tests/Component/Explorer/ResultTableComponentTest` — populated state renders one `<tr>` per row; empty state shows actionable copy; loading shows 5 skeleton rows.
- [ ] **9.2** Implement: `App\Twig\Components\Explorer\ResultTable` (passive) with state-aware template.
- [ ] **9.3** Test: `tests/Functional/Explorer/RowsFragmentEndpointTest` — `GET /tenants/{slug}/explore/{signal}/_rows?cursor=…` returns an HTML fragment containing only `<tr>` rows.
- [ ] **9.4** Implement: `ExplorerController::tableFragment` route + template.
- [ ] **9.5** Implement: `assets/controllers/table_controller.js` — handles prev/next click, fetches `_rows`, swaps tbody, updates `history.replaceState` with new cursor.

## Trace waterfall page

- [ ] **10.1** Test: `tests/Functional/Waterfall/WaterfallAccessTest` — anonymous → 302 login, non-member → 403, unknown trace → 404, member → 200.
- [ ] **10.2** Implement: `App\Controller\WaterfallController` route `GET /tenants/{slug}/traces/{traceId}`. Reuses `TraceTreeAssembler`. Renders `templates/waterfall/index.html.twig`.
- [ ] **10.3** Test: `tests/Component/Waterfall/RowComponentTest` — span row shows depth indent and duration bar with min-width 2 px when normalized width is sub-pixel.
- [ ] **10.4** Implement: `App\Twig\Components\Waterfall\Root`, `Header`, `Axis`, `RowList`, `Row`.
- [ ] **10.5** Test: `tests/Functional/Waterfall/SpanCapTest` — trace with 600 spans renders 500 rows + a "+100 more" inline message; depth-N descendants are omitted.
- [ ] **10.6** Implement: 500-span cap with descendant pruning.

## Trace waterfall sidebar (Live)

- [ ] **11.1** Test: `tests/Component/Waterfall/SidebarComponentTest` — empty state shows "← select a span" copy; populated state shows attributes/status/events/resource.
- [ ] **11.2** Implement: `App\Twig\Components\Waterfall\Sidebar` (AsLiveComponent) with LiveProp `selectedSpanId`. LiveAction `selectSpan(spanId)`.
- [ ] **11.3** Test: `tests/Functional/Waterfall/SidebarLiveActionTest` — valid spanId → 200 + attributes; bogus spanId → 404; cross-trace spanId (belongs to another trace) → 404.
- [ ] **11.4** Implement: voter re-check inside `selectSpan`; cross-trace defense.
- [ ] **11.5** Test: `tests/Component/Common/AttributesTableComponentTest` — populated, empty, loading.
- [ ] **11.6** Implement: `App\Twig\Components\Common\AttributesTable` (reused inside the sidebar three times).

## Drill to logs

- [ ] **12.1** Test: `tests/Functional/Waterfall/DrillToLogsLinkTest` — sidebar contains a `→ logs (this trace, ±5s)` link with the right href.
- [ ] **12.2** Implement: link in the sidebar template, query-string built from span `start_time_unix_nano` ± 5 s.

## Polish

- [ ] **13.1** Test: `tests/Functional/Explorer/EmptyStateCopyTest` — for each signal, an explorer with zero data shows the actionable empty-state copy in the table and chart.
- [ ] **13.2** Implement: any missing copy.
- [ ] **13.3** Test: `tests/Functional/Explorer/CursorPaginationStaysOnPageTest` — clicking next paginator does not change the chart canvas data attribute.
- [ ] **13.4** Implement: any missing wiring.
- [ ] **13.5** Manual smoke test: hand-test all four states for KPI tile / chart / table / sidebar in dev. Document which states are JS-dependent in `templates/explorer/README.md` (or skip the README if not customary in this repo).

## Coverage gate

- [ ] **14.1** Run `composer coverage:gate` and confirm line coverage stays >=80%. Each new file under `src/Explorer/`, `src/Controller/Explorer*`, `src/Twig/Components/`, and `assets/controllers/` SHALL be at >=80%.

## Deploy

- [ ] **15.1** Run `composer cs:fix` and `composer phpstan` locally; commit any fixes.
- [ ] **15.2** Push to `main`. Wait for CI green (Quality + PHPUnit + coverage gate).
- [ ] **15.3** Deploy to production via `~/.composer/vendor/bin/dep deploy production`. Smoke test the explorer page after deploy.
