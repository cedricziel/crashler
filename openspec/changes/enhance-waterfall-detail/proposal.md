## Why

The trace waterfall shipped as a deliberate v1: a depth-first span tree, an indigo bar per span, a click-to-load sidebar. Two latent bugs were exposed by the recent retention-wide waterfall fallback (sidebar still uses 24h; `aria-selected` highlight CSS is dead because nothing sets the attribute), and the page leaves five real ergonomic wins on the floor:

1. The sidebar starts empty. Users land on a trace and have to click before seeing any context.
2. Every bar is indigo. Distinguishing CLIENT vs SERVER vs INTERNAL spans requires reading the row label every time.
3. The only error signal is "the bar turns solid red" — easy to miss in a 100-span trace, easy to confuse with the kind-color we want to add.
4. There is no minimap. Long traces scroll without orientation.
5. The trace `resolve()` reads every span's `attributes_json` / `events_json` / `resource_attributes_json` even though the tree render uses none of them — bytes wasted on big traces.

## What Changes

- **Sidebar inherits the waterfall's time window** (P0 bug fix). Today the sidebar's per-span lookup is hard-pinned to `spanLookupWindowHours=24`, so any trace older than a day renders the tree fine but every span click returns null and the sidebar stays in its empty state forever.
- **`aria-selected` is actually set** (P0 bug fix). The CSS rule exists; nothing emits the attribute. Wire it through the page template from the Sidebar component's `selectedSpanId` LiveProp.
- **Root span is the initial selection.** The controller passes the root's `spanId` into the page; the Sidebar mounts with `selectedSpanId` preset; the root row paints with `aria-selected="true"`; the sidebar already shows attributes / status / events / resource on first paint. Side effect: `?spanId=…` permalinks are free (the LiveProp is already writable).
- **Color spans by `SpanKind`.** Distinct palette for UNSPECIFIED / INTERNAL / SERVER / CLIENT / PRODUCER / CONSUMER. Error indicator composes — bar keeps its kind color, gains a red right-edge stripe and a `⚠` glyph in the row label. Header gains a "N errors · jump to first" affordance when any span has `statusCode == 2`.
- **Minimap above the waterfall.** Full-trace overview at ~3rem tall, server-rendered using the same `leftPct` / `widthPct` shapes as the main view. Stimulus controller wires a draggable viewport rectangle to the main tree's `scrollLeft`.
- **Project the tree-render scan.** Extend `ParquetScanner::scan()` to accept a `columns: list<string>` whitelist. `TraceWaterfallResolver::resolve()` requests only the 8 columns the bar render needs; attribute/event blobs only get read when the sidebar fetches a single span.

## Impact

**Affected code (new):**
- `src/Twig/Components/Waterfall/Minimap.php` + `templates/components/waterfall/minimap.html.twig` — minimap component.
- `assets/controllers/minimap_controller.js` — viewport drag-to-scroll.

**Affected code (modified):**
- `src/Twig/Components/Waterfall/Sidebar.php` — add `windowSinceNs` / `windowUntilNs` writable LiveProps; use them in `span()` instead of the 24h fallback.
- `src/Controller/WaterfallController.php` — compute the root span's id from the resolver output, pass to template and Sidebar; pass the resolved window through.
- `src/Explorer/TraceWaterfallResolver.php` — add `spanKind` and a `hasError` flag to the shaped span output. Request a column-projected scan.
- `src/Read/Compute/ParquetScanner.php` — extend `scan()` with an optional `columns` whitelist that flows through to the flow-php `values()` call.
- `templates/waterfall/index.html.twig` — kind-keyed bar color, error stripe + glyph, header "N errors · jump", aria-selected wiring, mount minimap.
- `templates/components/waterfall/sidebar.html.twig` — display SpanKind label + status message elevation.

**Tests:**
- `tests/Component/Waterfall/SidebarComponentTest` — span-lookup uses inherited window (no 24h pinning).
- `tests/Component/Waterfall/TraceWaterfallResolverTest` — column projection works (no `attributes_json` in tree-render rows); spanKind surfaces.
- `tests/Functional/Waterfall/WaterfallAccessTest` — root pre-selected on initial paint; `?spanId=…` permalink swaps the selection.
- `tests/Component/Waterfall/MinimapComponentTest` — minimap renders one mini-bar per span, kind-color preserved.
- `tests/Component/Read/ParquetScannerTest` — column projection only reads requested columns.

## Capabilities

### Modified Capabilities
- `explorer-ui`: tightens the waterfall requirement set — root preselect, kind-color contract, minimap requirement, error indicator that composes with kind color.
