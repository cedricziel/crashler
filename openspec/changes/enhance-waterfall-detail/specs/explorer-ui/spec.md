## MODIFIED Requirements

### Requirement: Trace waterfall page with 80/20 layout

The system SHALL expose a waterfall page at `/tenants/{slug}/traces/{traceId}`. Access SHALL require `READ` permission on the tenant. The page SHALL render an 80/20 horizontal split:

- **Left 80 %**: time-axis header + indented span row list. Each span row SHALL show the span name, depth-indented, and a duration bar positioned within the trace's `[startNs, endNs]` window. Bars with width < 2 px SHALL be drawn at 2 px so they are clickable. The render SHALL be capped at 500 spans; if the trace has more, an inline "+N more" message SHALL appear and the depth-N descendants SHALL be omitted. A row-level minimap SHALL render above the axis showing all spans at small scale; the minimap SHALL include a draggable viewport rectangle that scrolls the main tree.
- **Right 20 %**: a sidebar that SHALL display the root span's attributes / status / events / resource on first paint. When the URL query string contains `spanId=…` the sidebar SHALL preselect that span instead. When the user clicks a span row the sidebar SHALL re-render server-side (Live component LiveAction) showing the selected span's detail.

Each span row SHALL carry `aria-selected="true"` when its `spanId` equals the sidebar's currently selected span, and `aria-selected="false"` otherwise. The visual highlight applied to the selected row SHALL be driven entirely by that attribute (no separate state machine).

Bars SHALL be coloured by `SpanKind`:
- UNSPECIFIED → neutral gray
- INTERNAL → slate
- SERVER → indigo
- CLIENT → amber
- PRODUCER → teal
- CONSUMER → rose

Spans with `statusCode == 2` (ERROR) SHALL retain their kind-coloured fill and gain a red right-edge stripe + a `⚠` glyph in the row label. The page header SHALL display an `N errors · jump to first ↓` affordance whenever the trace has at least one errored span; activating it SHALL scroll the first errored span into view and select it. These three error indicators (bar stripe, row glyph, header banner) SHALL all be present whenever any span is errored — the redundancy is the contract.

#### Scenario: Waterfall renders the full trace with root preselected
- **WHEN** a tenant member opens `/tenants/acme-prod/traces/4f2e…`
- **THEN** the response is 200
- **AND** the response contains one span row per span in the trace tree (up to 500)
- **AND** the row corresponding to the trace's root span carries `aria-selected="true"`
- **AND** the sidebar renders that root span's attributes / status / events / resource

#### Scenario: Permalink targets a non-root span
- **WHEN** a user opens `/tenants/acme-prod/traces/4f2e…?spanId=ab12…`
- **THEN** the row matching `spanId=ab12…` carries `aria-selected="true"`
- **AND** the root row carries `aria-selected="false"`
- **AND** the sidebar renders `ab12…`'s detail

#### Scenario: Click on a span row loads the sidebar
- **WHEN** the user clicks a span row (Live action `selectSpan` with the span ID)
- **THEN** the sidebar re-renders with the span's attributes
- **AND** the previously-selected row's `aria-selected` becomes `false`
- **AND** the newly-selected row's `aria-selected` becomes `true`
- **AND** no full page reload occurred

#### Scenario: Bogus span ID is rejected
- **WHEN** a Live action is invoked with a span ID that does not belong to the trace
- **THEN** the action returns 404
- **AND** the sidebar continues to show the previously-selected span (if any) or the empty state

#### Scenario: Sidebar inherits the page's time window
- **WHEN** the waterfall page resolves a 3-day-old trace via the retention-wide fallback
- **AND** the user clicks any span row
- **THEN** the sidebar's span lookup uses the same time window the page resolved against
- **AND** the click renders the selected span's detail (not the empty state)

#### Scenario: SpanKind drives bar color
- **WHEN** the waterfall renders spans of kinds `INTERNAL`, `SERVER`, `CLIENT`
- **THEN** their bars use the slate, indigo, and amber palette entries respectively
- **AND** no two distinct kinds share a color

#### Scenario: Errored span composes kind color with error indicators
- **WHEN** a `CLIENT` span has `statusCode == 2`
- **THEN** its bar keeps the amber kind-color fill
- **AND** the bar carries a red right-edge stripe
- **AND** the row label contains a `⚠` glyph
- **AND** the page header surfaces an `N errors · jump to first ↓` link

#### Scenario: Minimap reflects the main waterfall
- **WHEN** the waterfall renders any trace
- **THEN** a minimap appears above the time axis
- **AND** the minimap renders one small bar per span at the same horizontal position as the main view
- **AND** the minimap's viewport rectangle reflects the visible region of the main tree
- **AND** dragging the viewport rectangle scrolls the main tree

## ADDED Requirements

### Requirement: Tree-render scan reads only the columns the bar needs

The `TraceWaterfallResolver::resolve()` Parquet scan SHALL project to only the columns required for the bar render (`span_id_hex`, `parent_span_id_hex`, `name`, `start_time_unix_nano`, `end_time_unix_nano`, `status_code`, `status_text`, `resource_service_name`, `span_kind`). Attribute / event / resource-attribute JSON blobs SHALL NOT be read during the tree-render scan; they SHALL only be read by the per-span sidebar lookup that consumes exactly one row.

The `ParquetScanner::scan()` method SHALL accept an optional `columns: list<string>` whitelist; an empty list (the default) preserves today's all-columns behaviour.

#### Scenario: Tree-render rows omit attribute / event blobs
- **WHEN** the waterfall resolver loads a 500-span trace
- **THEN** the rows returned to the controller do not carry the `attributes_json` / `events_json` / `resource_attributes_json` columns
- **AND** the sidebar's per-span lookup for any selected span still returns those columns
