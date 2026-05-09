## Context

The read API is a row-shaped contract: filter, page, follow `_links`. That contract is the right primitive but the wrong unit-of-answer for the most common operator workflows. "Show me the events" is a noun question; "How many ERROR events?" is a verb question. The verb question is how dashboards are built and how alerting is computed.

The market has converged on three shapes for this:
- **Loki/Prometheus**: a query language with built-in aggregations (`count_over_time`, `rate`, `histogram_quantile`).
- **Tempo**: per-trace summarisation (TraceQL), not over collections.
- **Elastic / OpenSearch**: nested JSON aggregation buckets returned alongside hits.

Crashler does not have a query language and is not committing to one in v1.x. What we *do* have is a typed predicate compiler (the same one extended by `add-read-multi-attribute-filters` and reusable via `add-read-post-search`), a streaming scanner, and a stable column model. That is enough to support a small, fixed set of aggregation primitives without inventing new abstractions.

Stakeholders:
- Operators building dashboards (Grafana over a JSON datasource, internal tooling)
- Alerting / SRE workflows that need rate or percentile thresholds
- Future query-language compilers (LogQL-lite, TraceQL-lite) that would target these aggregations as a backend
- The compatibility-shim layer (see `add-read-compat-shims`) which leans on aggregations to satisfy Loki / Prometheus query shapes

## Goals / Non-Goals

**Goals:**
- A small fixed set of aggregation functions that covers >90% of dashboard/alert use cases: `count`, `sum`, `avg`, `min`, `max`, `p50`, `p90`, `p95`, `p99`.
- Composability with the existing GET search filter set: the same `service` / `severity` / `attribute.<key>` / time-window parameters that drive a search drive an aggregation.
- Optional `groupBy` over typed columns from a closed allow-list.
- Optional `interval` time bucketing.
- Bounded memory and time: caps on group cardinality and interval count so a single request cannot exhaust the process.
- Numeric stability for quantiles even on streaming row counts.
- A response shape that survives content negotiation across all four formats.

**Non-Goals:**
- A query language. We accept that "p99 of `request.duration` filtered by `route LIKE /orders/%`" is awkward in URL params; that is the job of `add-read-post-search` once it is generalised to aggregations (it is not, in this change).
- Histogram bucket exposition (returning a full distribution as a histogram). We expose discrete quantiles only.
- Sketch merging across distributed nodes. Single-process; one Crashler instance at a time.
- Sub-second granularity. The smallest interval is `1m`.
- COUNT DISTINCT on high-cardinality fields (`trace_id_hex`). The cardinality cap exists exactly to forbid this.
- Recommended aggregations from the OTLP exemplar/histogram fields on metrics (those are already aggregated upstream by the SDK). Metric aggregations target the *raw point columns* (`value_double`, `value_int`, `count`, `sum`) for re-aggregation.

## Decisions

### Decision 1: `GET` on `/v1/<plural>/aggregate`, not POST
**Choice:** aggregations are queryable via GET because the parameter set is small enough to fit comfortably in a URL: filters + `function` + optional `groupBy` + optional `interval`. POST aggregations come "for free" from `add-read-post-search` once that change is generalised; it is not in scope here.

**Why:** keeps the aggregate endpoint cacheable-by-URL, consistent with the GET search, and operable from a browser / curl / wget without a body.

### Decision 2: Closed allow-list of functions
**Choice:** v1 ships exactly the nine functions in the proposal (`count`, `sum`, `avg`, `min`, `max`, `p50/90/95/99`). Other percentiles (`p25`, `p999`) are punted; standard deviation, variance, mode, first/last are punted.

**Why:** small, well-understood, easy to reason about, easy to document, and accumulator-friendly. The `crashler.read.aggregate.functions` allow-list lives in code; adding a function in a future change is a one-line append plus an accumulator implementation.

### Decision 3: t-digest for percentiles
**Choice:** a pure-PHP t-digest (Dunning) implementation lives in `App\Read\Compute\Aggregations\TDigest`. Default compression `100` (gives ~1% error at p99 for streams in the millions). Clients selecting a percentile aggregation get the same accumulator pattern as `sum` / `avg`: per-group state grows by O(compression), not by row count.

**Alternatives considered:**
- **Exact percentiles via sorted arrays**: O(N) memory, OK for tiny groups but breaks at the row counts we expect.
- **Reservoir sampling**: simpler but quantile error is harder to bound.
- **HDR Histogram**: superb for fixed-range latency in microseconds but our value domain is open (any `value_double`).

**Why:** t-digest is the de-facto standard for streaming-quantile estimates over open value domains; pure-PHP makes it deployable on All-Inkl shared hosting without extensions.

### Decision 4: Group-by allow-list per signal
**Choice:** the `groupBy` parameter accepts only typed columns:

- Logs: `service`, `environment`, `host`, `severityText`, `severityNumber`
- Traces: `service`, `environment`, `host`, `kind`, `statusCode`, `name`
- Metrics: `service`, `environment`, `host`, `metricName`, `metricType`, `aggregationTemporality`

JSON-backed columns (`attributesJson`, `bodyJson`) are NOT allowed in `groupBy`. Multi-column group-by is allowed: `groupBy=service,kind`.

**Why:** group-by on JSON columns means a JSON-decode per row to derive the key — quadratic cost overhead on the scanner. Typed columns are O(1) per row. The closed list mirrors the existing filter columns.

### Decision 5: Cardinality cap is a hard error, not a truncation
**Choice:** when distinct group-key combinations exceed `crashler.read.aggregate.max_groups`, the response is HTTP 400 with a message naming the cap. We do NOT truncate, we do NOT return partial results.

**Alternative considered:** "top-N" semantics where the cap silently returns the largest N groups. Rejected — it changes the answer the operator gets in a non-obvious way; the semantics for "largest" introduces another knob (largest by which function?).

**Why:** silent truncation is the worst kind of bug for dashboards. A 400 with a "tighten your filters" message is correct and operator-actionable.

### Decision 6: Response shape is a flat list of result rows, no pagination
**Choice:** the response is a list of result rows; each row carries the group keys, the bucket-start (if interval is set), the function name, the function value, and a `sample_count`. There is no cursor pagination — the cardinality cap bounds the response size at `max_groups × max_intervals` (default 200 × 720 = 144,000 rows worst case, ~3 MB).

**Alternative considered:** paginate aggregations. Rejected — pagination over aggregation results is awkward (results are not naturally ordered without an extra `orderBy` parameter), and the worst-case row count is already bounded.

**Why:** simple, complete responses; clients render in one pass.

### Decision 7: Interval bucketing semantics
**Choice:** when `interval=<duration>` is set, the result is one row per `(group, bucket)`. Buckets are aligned to the duration in UTC: `interval=1h` produces buckets `[2026-05-09T13:00, 2026-05-09T14:00)`, etc. Empty buckets are NOT emitted (they are absent from the result, not zero-valued).

**Why:** UTC alignment is timezone-stable and matches every observability stack we know of. Empty-bucket suppression keeps the response small; clients that need zero-fill can do so trivially knowing the time window.

### Decision 8: Reuse the predicate machinery, not a parallel one
**Choice:** the aggregate processor compiles the same filter parameters into the same predicate classes used by the GET search. The scanner difference is one method: instead of "yield row to the result list", "feed row into the per-group accumulator". The Tier 0/1/2/4 ordering is unchanged; row-group push-down (`add-read-rowgroup-pushdown`) applies the same way.

**Why:** any improvement to the predicate compiler benefits the aggregate path without extra work. There is no copy of the predicate list anywhere.

## Risks / Trade-offs

- **Risk: t-digest implementation bugs** affecting quantile accuracy. → Mitigation: unit tests for known datasets (uniform, exponential, bimodal) asserting quantile error within 2% of the truth. Cross-check against Python's `tdigest` package outputs as a fixture source.

- **Risk: cardinality cap too low for legitimate workloads.** A 5000-host fleet exceeds the default 200 group cap. → Mitigation: the cap is configurable. The default of 200 fits the common case (per-service or per-route aggregations on a moderate fleet); operators with high-cardinality fleets raise it.

- **Risk: aggregations bypass the cursor pagination affordance.** → Mitigation: the response shape carries no `_links.next`; clients are expected to interpret a single response as the complete answer.

- **Risk: combination of `groupBy` and `interval` with high-cardinality columns** still produces large responses. → Mitigation: the `groupBy` cap and the `interval` cap multiply; the worst-case is `200 × 720 = 144_000` rows, kept under control by the existing execution-timeout.

- **Trade-off: PHP scalar accuracy.** `avg` on long-running counters can hit float-precision drift. → Mitigation: accept the standard `float64` semantics; counters in OTLP are bounded.

- **Trade-off: a dedicated scanner means duplicate code paths** between row-yielding and accumulator-feeding loops. → Mitigation: extract the per-row evaluation into a shared `iterRows()` helper; the scanner classes only differ in what they do with each surviving row.

## Migration Plan

- No data migration. No on-disk change.
- Roll-forward: deploy normally. New endpoints become available immediately. Existing GET search and by-ID lookups unchanged.
- Rollback: revert. Aggregate endpoints become 404. GET search continues to serve.
- Communication: README adds an "Aggregations" subsection with `count`/`p99`/`groupBy` worked examples; OpenAPI documents every endpoint per the new examples global rule.

## Open Questions

- Should the aggregate endpoint return a `_links.search` affordance pointing back at the GET search with the same filters, so a user can drill from "p99 was high" to "show me the slow rows"? Yes — encoded in the spec scenarios.
- Should we expose a `bucketsJson` aggregation for the histogram metric type (raw aggregated buckets re-aggregated)? Tempting but specific to the metrics signal and to the histogram metric type. Defer until requested; can be added without breaking changes.
- Does the cardinality cap interact with the JSON-attribute filter cap from `add-read-multi-attribute-filters`? They are independent: attribute-filter cap bounds the predicate count; group-cap bounds the result size. Both apply simultaneously.
