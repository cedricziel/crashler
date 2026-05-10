## Why

Today the Read API (`POST /v1/{logs,traces,metrics}/search`, `/aggregate`, `/{traceId}`) is only consumable via `curl + jq` or by integrators writing their own client. There is no first-party UI that lets a tenant member explore their own logs, traces, and metrics in a browser. This blocks self-service investigation, demos, and the "did my OTLP collector actually send anything" feedback loop that observability users expect within seconds of pointing a collector at the system.

We have all the backend pieces (search, aggregate, trace tree, cursor pagination) and the Symfony stack (Twig + AssetMapper + Stimulus + Turbo) already wired. What's missing is a thin, server-rendered query interface on top.

## What Changes

- **NEW** `/tenants/{slug}/explore/{signal}` page (`signal ∈ {logs, traces, metrics}`) — a single layout shell with the same five rows for every signal: signal-tabs, KPI strip, chart, criteria & aggregation form, results table.
- **NEW** `/tenants/{slug}/traces/{traceId}` waterfall page — 80/20 split, full span tree on the left, click-to-load span detail sidebar on the right (span attributes, status, events, resource attributes, drill-to-logs link).
- **NEW** `App\Explorer\SignalProfile` interface with three implementations (logs / traces / metrics). Each profile declares the signal's KPIs, chart series, filter dimensions, table columns, and row-click action. The shell template branches on the profile, not on the signal name.
- **NEW** `composer require symfony/ux-twig-component symfony/ux-live-component` to unlock `<twig:…>` syntax + LiveProp for the few components that actually need server state.
- **NEW** Stimulus controllers `chart` and `table` driving canvas-rendering and cursor pagination. uPlot for charts (small bundle, fast, fits the use case).
- **NEW** Brush-select on the chart sets `since/until` and re-runs the page (URL is the source of truth; brush-select dispatches a `CustomEvent` that the QueryForm listens for).
- The Read API itself is **not** modified. The UI is a pure consumer of the existing OpenAPI contract.
- All five KPI tiles per signal page resolve to **two** parallel calls to `GET /v1/{signal}/aggregate` (current window + comparison window). Five KPIs share a single aggregate scan via different `function`/`column` combinations where possible; where they don't, additional in-process calls run concurrently.
- **Every component ships with all four states from day one**: `loading`, `empty`, `error`, `populated`. Empty-state copy is part of the component contract, not an afterthought. Loading uses server-rendered skeletons that preserve layout (no spinners for full-page loads); explicit user actions (e.g. clicking "Run") surface an inline spinner inside the button. Live components reuse their built-in `loading` attribute for in-place greying. The full state matrix per component lives in `design.md` and is enforced by a component test that exercises each state.

## Capabilities

### New Capabilities
- `explorer-ui`: defines the contract for the user-facing telemetry query interface — routes, layout, signal-profile abstraction, brush-select interaction, trace waterfall view, and the rules around URL-as-source-of-truth and shareable queries.

### Modified Capabilities
<!-- None. The Read API spec is unchanged; the UI is a pure consumer of it. -->

## Impact

**Affected code (new):**
- `src/Explorer/` — `ExplorerController`, `WaterfallController`, `SignalProfile` + 3 implementations, KPI bundle resolver, brush-select event contract.
- `templates/explorer/` — root layout, signal tabs, KPI strip + tile, chart shell, query form (Live), filter chip (Live), time range picker (Live), aggregation controls (Live), result table, paginator, attributes table.
- `templates/waterfall/` — root, header, axis, row list + row, sidebar (Live).
- `assets/controllers/` — `chart_controller.js`, `table_controller.js`.
- `assets/vendor/` — uPlot pinned via importmap.
- `tests/Functional/Explorer/` + `tests/Component/Explorer/` — page renders, brush-select event, waterfall sidebar fetch, signal profile contract.

**Affected code (modified):**
- `assets/app.js` — register the two new Stimulus controllers and import uPlot's CSS.
- `templates/_partials/` — extracted `nav.html.twig` if not already present, to host the signal tabs in a consistent way.
- `tests/Support/DatabaseTestCase` — small helper for seeding parquet files via the existing ingest pipeline (so the explorer tests have something to render).

**Dependencies:**
- `symfony/ux-twig-component` (new)
- `symfony/ux-live-component` (new)
- uPlot (vendored via AssetMapper importmap)

**Quality / coverage:**
- Test strategy:
  - **Component tests**: render each `<twig:…>` component in isolation with stub props; snapshot the HTML for KPI tile, filter chip, attributes table, paginator. Component tests for Live components verify the LiveProp wiring renders.
  - **Functional tests**: `WebTestCase` for both pages — `/tenants/{slug}/explore/logs|traces|metrics` and `/tenants/{slug}/traces/{id}` — covering happy path (200 + correct content), permission denied (403 for non-members), and brush-select URL round-trip (POST/redirect with new `since/until`).
  - **Functional test for the Live waterfall sidebar** — click a span (simulated via the LiveAction endpoint), assert the response fragment contains the right span's attributes.
- Coverage delta target: every new file in `src/Explorer/` and `src/Waterfall/` must land at >=80% line coverage. The full project line-coverage gate (`composer coverage:gate`, threshold 80%) MUST stay green.
- The change does not touch any code path currently below 80% coverage — all new code is additive.
