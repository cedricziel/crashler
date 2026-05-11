# Design — `enhance-waterfall-detail`

## Overview

Five user-facing changes + two latent-bug fixes + one resolver refactor. The lot composes into a single page with a clearer information density.

```
┌─ HEADER ────────────────────────────────────────────── 3 errors · jump ↓ ─┐
│   POST /api/orders                                                        │
│   eb13…d156 · checkout · 47 spans · 482.31 ms · 2026-05-11 12:14:32 UTC   │
├───────────────────────────────────────────────────────────────────────────┤
│   MINIMAP   ░░▓░░▓░░░░░░░░░░░░░░▓▓▓▓▓░░░░░░░░░░░▒▒▒▒░░░░░░░░░░░░  482 ms │
│             │◄── viewport ──►│   (drag to scroll the tree below)          │
├──────── KIND  ░ INTERNAL  ▓ SERVER  ▒ CLIENT  ░ PRODUCER  ▓ CONSUMER ─────┤
│  AXIS  0 ──── 120 ──── 240 ──── 360 ──── 482 ms │  SIDEBAR (root        │
│                                                 │   preselected)         │
│  checkout  POST /api/orders        ███████████  │                        │
│  ↳ db      SELECT users            ▓▓▓          │   POST /api/orders     │
│  ↳ ext     POST /charge            ▓▓▓▓▓▓▓▓▓ ⚠  │   CLIENT · ERROR       │
│  ↳ ↳ retry                         ▓▓ ⚠         │                        │
│  ↳ payments validateCard           ▓▓▓▓         │   Attrs / Events / …   │
└──────── selected row gets aria-selected="true" + yellow tint ────────────┘
```

## SpanKind palette

```
UNSPECIFIED  (0)  neutral gray   #cbd5e1
INTERNAL     (1)  slate          #64748b
SERVER       (2)  indigo         #4f46e5      ← what every bar is today
CLIENT       (3)  amber          #d97706
PRODUCER     (4)  teal           #0d9488
CONSUMER     (5)  rose           #db2777
```

Selection criteria: each color distinct under typical (and red/green colorblind) viewing; none of them are the error red `#c33`; INTERNAL is the muted slate so trace-internal plumbing recedes against business-relevant edge spans.

Error composes — bar keeps its kind fill, gains a 3px red right-edge stripe and a `⚠` glyph in the row label.

## Sidebar window inheritance

```
Today:
  Sidebar::span() ──► TimeWindow::parse(['since' => '24h']) ──► PartitionPruner ──► scan

New:
  WaterfallController resolves the page window (URL `since`/`until` or full retention).
  Passes it through to the Sidebar component as windowSinceNs/UntilNs LiveProps.
  Sidebar::span() builds a TimeWindow from those props and scans inside the same range.
```

The Sidebar LiveProps are `writable: false` for these two — they shadow the controller's authoritative window. (Only `selectedSpanId` stays writable, so click-through still works.)

## Root preselection

```
Controller:
  $trace = $resolver->resolve($slug, $traceId, $window);
  // resolver already picks "first by start time" among parentless rows
  $rootSpanId = $trace['rootSpanId'];  // ← new field

Template:
  <twig:Waterfall:Sidebar
      tenantSlug="…" traceId="…"
      windowSinceNs="…" windowUntilNs="…"
      selectedSpanId="{{ request.query.spanId|default(trace.rootSpanId) }}"
      id="waterfall-sidebar"/>
```

Permalink (`?spanId=…`) takes precedence so shared URLs reopen with the right span selected. Falls through to root when absent.

`aria-selected` is computed in the page template per row: `aria-selected="{{ span.spanId == selectedSpanId ? 'true' : 'false' }}"`. The `selectedSpanId` value comes from the same `request.query.spanId|default(trace.rootSpanId)` expression — no client-side wiring needed for first paint.

## Minimap

Server-rendered, ~3rem tall, full width.

```
Component:  App\Twig\Components\Waterfall\Minimap (passive)
  Props:  list<span>  (the same shaped spans the main view uses)
          traceDurationMs  (header label)

Template renders:
  <div data-controller="minimap"
       data-minimap-tree-selector-value=".waterfall-tree">
    ─ axis label row (0 ms ──── 482 ms)
    ─ scrollable bar-stack: one absolute-positioned div per span,
      kind-color fill, error stripe — same leftPct/widthPct as main view.
      Each mini-bar is 2px tall + 1px row gap.
    ─ viewport indicator: an absolutely positioned <div> sized to the
      ratio of (visible-tree-width / total-tree-width).
  </div>
```

Stimulus `minimap_controller.js`:
- On connect: measure viewport ratio from the tree element's scrollLeft / scrollWidth, position the viewport indicator.
- On tree scroll: reposition the viewport indicator (one rAF-coalesced update).
- On viewport mousedown + drag: convert minimap-relative X to tree.scrollLeft.

No selection interaction in v1 — minimap is for navigation only.

## Column projection

`ParquetScanner::scan()` gains an optional `columns: list<string>` parameter; defaults to `[]` (everything, current behaviour). Passes straight through to `parquetFile->values(columns: $columns, …)`.

`TraceWaterfallResolver::resolve()` requests the 9 columns the bar render needs:
```
['span_id_hex', 'parent_span_id_hex', 'name', 'start_time_unix_nano',
 'end_time_unix_nano', 'status_code', 'status_text', 'resource_service_name',
 'span_kind']
```

`Sidebar::span()` continues to scan all columns (it's looking at exactly one row and needs the attribute / event blobs). No change there.

## Error affordances

Three layers, all redundant:

```
1. BAR:   red right-edge stripe + retain kind color fill
2. ROW:   faint red row background tint (#fef2f2)
          ⚠ glyph in the row label
3. HEADER: "N errors · jump to first ↓" link that scrolls + selects
          the first errored span
```

The triple-redundancy is intentional — the bar tells you mid-scan, the row tells you when reading labels, the header tells you globally.

## Risks

```
A. SpanKind values on disk are integers per OTLP convention. If any
   tenant's partitions carry strings, the kind-color logic must fall
   back to UNSPECIFIED. Trivial to handle in shapeSpan().

B. Minimap drag interaction on traces with very wide containers (>3000px
   tree) needs smooth-enough scrollLeft updates. Single rAF coalescing
   should be sufficient; if not, debounce to 16ms.

C. Column projection on a flow-php Parquet read may return a row dict
   missing the unrequested keys (vs setting them to null). The resolver
   already uses `$row['key'] ?? null` everywhere; safe by construction.

D. The waterfall's existing `aria-selected` CSS already exists. The fix
   is one twig variable, no risk.
```

## Out of scope (deferred to follow-ups)

- Critical-path highlight, self-time vs total-time computation
- Service swimlanes (group rows by service.name)
- Span links / async edge visualisation
- Search-within-trace
- Collapse / expand subtrees
- Wall-clock timestamp ticks on the axis
- Compare-with-prior-trace
