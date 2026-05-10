## ADDED Requirements

### Requirement: Tenant-scoped telemetry explorer page

The system SHALL expose a server-rendered explorer UI at `/tenants/{slug}/explore/{signal}` for `signal ∈ {logs, traces, metrics}`. The page SHALL be reachable only by users with `READ` access to the tenant (enforced via the existing `TenantVoter`). Anonymous requests SHALL be redirected to `/login`. Tenant non-members SHALL receive HTTP 403.

The page SHALL render five rows in fixed order:
1. Signal-tab nav (logs / traces / metrics, current highlighted)
2. KPI strip (5 tiles)
3. Chart (full-width canvas with brushable selection)
4. Criteria & aggregation form (filters, time range picker, function/column/groupBy/interval)
5. Results table + paginator + export controls

The layout SHALL be identical across the three signals; only the per-row content (KPI definitions, chart series, available filters, table columns) varies. The variation SHALL be encapsulated in an `App\Explorer\SignalProfile` interface with one implementation per signal.

#### Scenario: Tenant member reaches the logs explorer
- **WHEN** a user with the `Member` role on tenant `acme-prod` requests `/tenants/acme-prod/explore/logs`
- **THEN** the response is 200
- **AND** the rendered HTML contains a signal-tab nav with `logs` marked active
- **AND** a KPI strip with five tiles is present
- **AND** an empty `<canvas data-controller="chart">` element is present
- **AND** a `<form data-controller="live">` for the QueryForm component is present
- **AND** a `<table>` with the logs-specific column set is present

#### Scenario: Anonymous request is redirected to login
- **WHEN** an unauthenticated request hits `/tenants/acme-prod/explore/traces`
- **THEN** the response is a 302 redirect to `/login`

#### Scenario: Non-member is forbidden
- **WHEN** a user without any membership on tenant `acme-prod` requests `/tenants/acme-prod/explore/metrics`
- **THEN** the response is 403

#### Scenario: Unknown signal returns 404
- **WHEN** a request hits `/tenants/acme-prod/explore/foo`
- **THEN** the response is 404

### Requirement: URL is the source of truth for query state

Every relevant piece of query state — `since`, `until`, filter chips, `function`, `column`, `groupBy`, `interval`, current cursor — SHALL be represented in the URL query string. Sharing the URL SHALL reproduce the exact same view for any user with read access. The query form's Live component SHALL reflect the URL on first render.

#### Scenario: Shareable URL reproduces the same query
- **WHEN** a tenant member opens `/tenants/acme-prod/explore/logs?since=2026-05-09T13:00:00Z&until=2026-05-09T15:00:00Z&service=checkout&function=count&groupBy=service`
- **THEN** the page renders with the time range picker prefilled to that window
- **AND** the filter chip strip shows a single chip `service: checkout`
- **AND** the aggregation controls show `function=count`, `groupBy=service`

### Requirement: Brush-select on the chart updates the time window

The chart SHALL support a brush-select gesture (drag a horizontal range across the chart). When the user releases the brush, the system SHALL update the URL's `since` and `until` query parameters to the brushed range, refresh all five rows of the page (KPI / chart / form / table / paginator), and add a new history entry so the back button returns to the prior window.

The brush gesture SHALL be debounced to a single round trip even if the user adjusts the selection rapidly. Pressing `Escape` SHALL cancel an in-progress selection. Double-clicking the chart SHALL reset to the page's original window. Shift-clicking the chart SHALL reset to the default window (last 1 hour).

#### Scenario: Brush updates the URL and re-renders
- **WHEN** a user drags from `t1=14:32` to `t2=14:55` on the logs chart
- **THEN** within 300ms the URL's query string contains `since=…14:32:00Z` and `until=…14:55:00Z`
- **AND** the chart, KPI strip, and table all re-render against the new window
- **AND** the browser history has a new entry (back button returns to prior view)

### Requirement: KPI strip shows current value and prior-period delta

Each KPI tile SHALL show the value for the current time window and a delta versus the immediately-prior window of the same width. The delta SHALL be rendered as `▲N%` (increase, red if "more is bad" e.g. errors), `▼N%` (decrease, green if more is bad), or `—` (no comparable prior). KPI definitions SHALL be declared by the `SignalProfile` for each signal.

The KPI bundle SHALL de-duplicate aggregate calls: KPIs that share `function` + `column` + filter set SHALL share a single backend aggregate scan per window.

#### Scenario: KPI tile shows delta vs prior period
- **WHEN** the logs explorer renders for window `[14:00, 15:00]` with 100 logs in current and 80 in `[13:00, 14:00]`
- **THEN** the "total" KPI tile shows `100` with `▲ 25 %`

#### Scenario: KPI tile shows em-dash when prior data is unavailable
- **WHEN** no parquet files exist for the prior window
- **THEN** the affected KPI tiles display `—` for the delta

### Requirement: Each component declares all four states

Every Twig component used in the explorer SHALL render correctly in four states: `loading`, `empty`, `error`, `populated`. Each state SHALL be exercised by a component-level test. Empty-state copy SHALL be part of the component contract (not retrofitted) and SHALL include actionable guidance (e.g., "Try widening the time range").

Loading states SHALL use server-rendered skeletons that preserve layout to avoid content jumps. Spinners SHALL be reserved for explicit user actions (e.g., a `Run` button shows a spinner while a re-render is in flight). Live components SHALL use the LiveProp `loading` attribute for in-place greying.

#### Scenario: Empty result table shows actionable copy
- **WHEN** a search returns zero rows
- **THEN** the table body contains the copy "No rows match. Try widening the time range or removing filters."
- **AND** no skeleton placeholder rows are visible

#### Scenario: Loading state preserves layout
- **WHEN** a Live action on the QueryForm is in flight
- **THEN** the form is rendered with `aria-busy="true"`
- **AND** the form's bounding box height matches the populated state's height ±2px

### Requirement: Trace waterfall page with 80/20 layout

The system SHALL expose a waterfall page at `/tenants/{slug}/traces/{traceId}`. Access SHALL require `READ` permission on the tenant. The page SHALL render an 80/20 horizontal split:

- **Left 80 %**: time-axis header + indented span row list. Each span row SHALL show the span name, depth-indented, and a duration bar positioned within the trace's `[startNs, endNs]` window. Bars with width < 2 px SHALL be drawn at 2 px so they are clickable. The render SHALL be capped at 500 spans; if the trace has more, an inline "+N more" message SHALL appear and the depth-N descendants SHALL be omitted.
- **Right 20 %**: a sidebar that initially shows the empty state copy "← select a span to see attributes, status, events, resource". When the user clicks a span row, the sidebar SHALL re-render server-side (Live component LiveAction) showing the selected span's attributes, status, events, resource attributes, and drill-to-logs link.

#### Scenario: Waterfall renders the full trace
- **WHEN** a tenant member opens `/tenants/acme-prod/traces/4f2e…`
- **THEN** the response is 200
- **AND** the response contains one span row per span in the trace tree (up to 500)
- **AND** the sidebar is in its empty state

#### Scenario: Click on a span row loads the sidebar
- **WHEN** the user clicks a span row (Live action `selectSpan` with the span ID)
- **THEN** the sidebar re-renders with the span's attributes
- **AND** the empty-state copy is no longer visible
- **AND** no full page reload occurred

#### Scenario: Bogus span ID is rejected
- **WHEN** a Live action is invoked with a span ID that does not belong to the trace
- **THEN** the action returns 404
- **AND** the sidebar continues to show the previously-selected span (if any) or the empty state

### Requirement: Drill from waterfall to linked logs

The waterfall sidebar SHALL include a "→ logs (this trace, ±5s)" link. Following the link SHALL navigate to `/tenants/{slug}/explore/logs?…` with `traceId={traceId}`, `since`, and `until` set to the span's start ± 5 seconds. The destination is the existing logs explorer; no new view is required.

#### Scenario: Drill link populates the logs explorer
- **WHEN** the user clicks "→ logs (this trace, ±5s)" in a span sidebar
- **THEN** the browser navigates to `/tenants/{slug}/explore/logs`
- **AND** the URL query contains `traceId={traceId}`
- **AND** the URL query's `since` is the span start - 5 s
- **AND** the URL query's `until` is the span start + 5 s

### Requirement: Cursor pagination without full page reload

The result table's prev/next paginator SHALL use Stimulus + fetch to swap the table body in place. The browser's URL SHALL be updated with the new cursor via `history.replaceState`. The chart and KPI strip SHALL NOT re-render when paging. Cursor values SHALL come from the existing Read API's HMAC-signed opaque cursors and SHALL pass through unmodified.

#### Scenario: Next-page click swaps tbody only
- **WHEN** the user clicks the "next" link in the paginator
- **THEN** an XHR is sent to `/tenants/{slug}/explore/{signal}/_rows?cursor=…`
- **AND** the response is an HTML fragment containing only the new `<tr>` rows
- **AND** the chart `<canvas>` is not re-fetched
- **AND** the URL's `cursor` query parameter reflects the new position

### Requirement: UI consumes the existing Read API contract

The explorer UI SHALL invoke the existing Read API endpoints (`POST /v1/{signal}/search`, `GET /v1/{signal}/aggregate`, `GET /v1/traces/{id}`) for all data resolution. The UI SHALL NOT bypass any read-side spec requirement (tenant scoping, time-window cap, page-size cap, cursor signature). When the same in-process Symfony kernel can be used (via `HttpKernelInterface::handle()` sub-request) the UI SHOULD prefer that to avoid serialization overhead, but the contract MUST match what an external client over HTTP would see.

#### Scenario: UI honors the time-window cap
- **WHEN** a user submits a query with a window > `crashler.read.max_time_window_days`
- **THEN** the explorer returns the same HTTP 400 the Read API would return
- **AND** the page re-renders with an inline error banner naming the configured cap
