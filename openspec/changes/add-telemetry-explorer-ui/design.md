# Design — `add-telemetry-explorer-ui`

## Overview

A server-rendered, tenant-scoped explorer UI on top of the existing Read API. One layout shell drives all three signals; a separate waterfall view handles trace detail. The UI is fluent — most interactions update without a full reload — but the URL stays the source of truth, so every view is shareable.

```
   Browser                                          Server (Symfony)
   ───────                                          ────────────────
   /tenants/{slug}/explore/logs?since=…&...   ───►  ExplorerController
   /tenants/{slug}/explore/traces?...                  └─ resolves SignalProfile
   /tenants/{slug}/explore/metrics?...                 └─ runs aggregate (KPIs)
                                                       └─ runs aggregate (chart)
                                                       └─ runs search (table page)
                                                       └─ renders <twig:Layout:Explorer>

   /tenants/{slug}/traces/{traceId}            ───►  WaterfallController
                                                       └─ GET /v1/traces/{id} (sub-request)
                                                       └─ TraceTreeAssembler
                                                       └─ renders <twig:TraceWaterfall>

   click brush on chart  ─► CustomEvent ─► QueryForm Live action ─► history.replaceState
                                                                  └─ POST /…/explore/_form
                                                                     re-renders form +
                                                                     triggers refresh()
```

## Component model — case-by-case (passive vs Live vs Stimulus-driven)

```
   Component                     Type           State held where
   ────────────────────────      ──────────     ──────────────────────────
   <twig:Layout:Explorer>        passive        URL query params (truth)
   <twig:Explorer:SignalTabs>    passive        active = current route
   <twig:Explorer:KpiStrip>      passive        prop from controller
   <twig:Explorer:KpiTile>       passive        prop
   <twig:Explorer:QueryForm>     LIVE           LiveProp on filter set,
                                                 since/until, agg controls
   <twig:Explorer:FilterChip>    LIVE-child     embedded in QueryForm
   <twig:Explorer:TimeRangePicker> LIVE-child   embedded in QueryForm
   <twig:Explorer:AggregationCtrls> LIVE-child  embedded in QueryForm
                                                 (column disabled when
                                                  fn=count)
   <twig:Explorer:Chart>         passive shell + Stimulus("chart")
                                  - Twig renders an empty <canvas> + data URL
                                  - Stimulus fetches /…/_chart.json and renders
                                    via uPlot
                                  - emits 'explorer:time-range-selected'
                                    on brush; QueryForm Live listens
   <twig:Explorer:ResultTable>   passive shell + Stimulus("table")
                                  - First page server-rendered (SEO + skeleton
                                    avoidance for the common case)
                                  - prev/next click → fetch /…/_rows?cursor=…
                                  - response is HTML fragment, swaps tbody
   <twig:Explorer:Paginator>     passive        prev/next cursors as props
   <twig:TraceWaterfall>         passive        whole tree as a flat list,
                                                 server-rendered once
   <twig:TraceWaterfall:Sidebar> LIVE           LiveProp selectedSpanId;
                                                 on click → re-renders just
                                                 the sidebar (no full reload,
                                                 no Stimulus glue)
   <twig:AttributesTable>        passive        leaf
```

## State matrix — every component, four states

```
   component                loading              empty                  error              populated
   ───────────────          ───────              ─────                  ─────              ─────────
   KpiTile                  skeleton pulse       "—"                    "?" tooltip        "12 480  ▲ 3.1 %"
   Chart                    skeleton bars        "No data in            error banner       canvas + brush
                                                  this window"           + retry
   QueryForm                LiveProp loading     defaults to last 1h    inline field       chips + inputs
                            (whole form greyed)                          validation
   FilterChip               pending grey         n/a                    red border         pill + ×
   ResultTable              skeleton 5 rows      "No rows match.        error banner       rows + cursor
                                                  Widen the time
                                                  range or remove
                                                  filters."
   Paginator                disabled "loading"   hidden                 n/a                ‹prev | n/m | next›
   TraceWaterfallList      skeleton depth-1     n/a                    error banner       indented tree + bars
   TraceWaterfallSidebar   skeleton panel       "← select a span"      error banner       attrs/status/events
   AttributesTable         skeleton rows        "No attributes."       n/a                key=value list
```

**Loading rules:**
- Server-rendered skeletons preserve layout (no jump when data arrives)
- Spinners only inside explicit user action buttons (`Run` button)
- Live components use built-in `loading` attribute for in-place grey

## Signal profile contract

```php
namespace App\Explorer;

interface SignalProfile
{
    public function name(): string;                     // 'logs' | 'traces' | 'metrics'
    public function searchEndpoint(): string;           // POST /v1/{signal}/search
    public function aggregateEndpoint(): string;        // GET /v1/{signal}/aggregate
    /** @return list<KpiSpec> exactly 5 */
    public function kpis(): array;
    /** @return list<FilterDefinition> */
    public function filters(): array;
    /** @return list<TableColumn> */
    public function tableColumns(): array;
    public function defaultGroupBy(): string;
    public function defaultColumn(): ?string;           // for fn=avg/sum default
    public function rowClickRoute(): ?string;           // 'app_trace_waterfall' for traces
}
```

Three implementations: `LogsProfile`, `TracesProfile`, `MetricsProfile`. Adding a fourth signal later is a fourth profile, not a fourth template.

## Brush-select interaction

```
   1. Stimulus chart_controller.js subscribes to uPlot's `setSelect` hook.
   2. On selection: dispatch CustomEvent(
        'explorer:time-range-selected',
        { since: t1, until: t2 }
      )
   3. QueryForm LiveComponent has a JS event listener that catches it,
      sets the `since`/`until` LiveProps, and emits a Live action that
      re-renders.
   4. After re-render, the QueryForm component triggers history.replaceState
      with new query params and fires its 'submit' (i.e. POST to the explore
      endpoint, which is just a redirect to GET with new params).
   5. Full page reloads — chart, table, KPIs all reflect the new window.
      Acceptable: parquet scans take 100ms+; a full re-render is honest.

   keyboard shortcuts (chart_controller):
     ESC          clear current selection (no submit)
     shift-click  reset to default window (last 1h)
     dbl-click    reset to original window
```

## KPI bundle

The KPI strip needs five values per signal. Implementation:

```
   KpiBundleResolver::resolve(SignalProfile $p, TimeWindow $current)
       → array<KpiSpec, KpiValue>

       internally:
       1. Build {current, prior} time windows (prior = same width, immediately before)
       2. Bucket KPIs by their underlying aggregate query signature
          (so {function=count, no groupBy} runs once and feeds 1+ KPIs)
       3. For each unique signature × {current, prior}, dispatch an
          in-process sub-request to /v1/{signal}/aggregate
       4. Use parallel curl handle? No — Symfony's HttpKernel is sync.
          Fire sequentially; aggregate scans are typically <100ms each so
          5 KPIs × 2 windows = ~1s worst-case. Acceptable for v1.
```

## Routes

```
   GET    /tenants/{slug}/explore/{signal}                 ExplorerController
   GET    /tenants/{slug}/explore/{signal}/_chart.json     ExplorerController::chartData
   GET    /tenants/{slug}/explore/{signal}/_rows           ExplorerController::tableFragment
   GET    /tenants/{slug}/traces/{traceId}                 WaterfallController
   POST   /_components/QueryForm                           (Live component endpoint)
   POST   /_components/TraceWaterfallSidebar               (Live component endpoint)
```

The Live component endpoints are auto-registered by `symfony/ux-live-component`; no explicit route definition needed.

## Security

- Both controllers run inside the existing `app` firewall.
- Both check `$this->denyAccessUnlessGranted(TenantVoter::READ, $tenant)` before any read.
- The Live `TraceWaterfallSidebar` re-checks the voter on every action — the LiveProp `traceId` is a hint, not authority.

## Charting library — uPlot

uPlot ships small (~12kb gzipped), renders to canvas, has a built-in selection/brush API, and has no React/Vue dependency. Pinned via AssetMapper importmap. CSS is one file, ~2kb. The minimal API surface fits the chart_controller's job: instantiate, push data, listen for select.

Chart.js was rejected: heavier, and its plugin model is overkill for "line + bar + brush".

## Testing strategy

```
   Unit (tests/Unit/Explorer/)
     - SignalProfile registry resolves logs/traces/metrics
     - KpiBundleResolver de-dupes aggregate calls correctly
     - PriorWindowCalculator yields correct comparison window

   Component (tests/Component/Explorer/)
     - <twig:Explorer:KpiTile> snapshot for loading/empty/error/populated
     - <twig:Explorer:FilterChip> renders with X close button
     - <twig:Explorer:Paginator> hides when no pages
     - <twig:AttributesTable> empty state copy
     - LiveComponent QueryForm renders form fields wired to LiveProps

   Functional (tests/Functional/Explorer/)
     - GET /tenants/acme-prod/explore/logs returns 200 with rendered chart
     - GET .../explore/traces with no data shows empty state copy
     - GET .../explore/metrics with malformed since returns 400
     - GET .../explore/logs as non-member returns 403
     - GET /tenants/.../traces/{id} renders waterfall + initial sidebar empty
     - LiveAction on TraceWaterfallSidebar with valid spanId loads attrs
     - LiveAction with bogus spanId 404s
     - Brush-select event roundtrip: simulate Live action with new since/until,
       assert URL params update

   Coverage target: each new file >=80%, project line coverage stays >=80%.
```

## Risks & mitigations

```
   risk                                           mitigation
   ─────────────────────────────────────────      ─────────────────────────────────
   uPlot bundle bloat on every page               importmap: lazy-load only on
                                                   /tenants/.../explore routes via
                                                   route-conditional import or a
                                                   separate assets entry-point

   parquet scans on every brush-select are        debounce brush-select by 200ms;
   slow → user drags → 5 scans fire               cancel previous fetch with
                                                   AbortController if a new
                                                   selection arrives

   Live components hold session-bound state →     disable session_factor for these
   stale cache when user opens 2 tabs              two specific Live components
                                                   (treat them as stateless)

   axis precision: bars < 1px disappear            min-width 2px on every span bar

   trace with 1000+ spans freezes                  cap render at 500 spans, show
                                                   "+N more (filter to narrow)"
                                                   message; add per-span filter
                                                   input (out of scope for v1?)
```

## Out of scope (v1)

- Saved queries / dashboards
- Multi-tenant overlay (tenant picker in header)
- Trace span search-within-trace input
- Real-time auto-refresh ("watch this query for new data")
- Drill from chart series → filtered query (clicking a series legend)
- Custom KPI definitions
- Linked logs drawer inside waterfall (we link to the logs explorer instead)
