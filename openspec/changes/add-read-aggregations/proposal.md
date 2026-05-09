## Why

The read API in v1 returns rows. That is correct and minimal — every observability question reduces to "show me the relevant events" — but most operator workflows actually want a *number* over those events:

- "How many ERROR-severity log records did `checkout` produce in the last hour?" → COUNT
- "What is the p99 latency of `GET /orders/*` spans over the last 30 minutes, by service?" → PERCENTILE
- "What is the request rate per minute for spans whose `http.status_code >= 500`?" → COUNT BUCKETED OVER TIME
- "What is the total `http.server.request.body.size` summed by `http.method`?" → SUM GROUPED

Today, satisfying any of these requires the client to page through the full row collection and aggregate in JavaScript or Go. That is bandwidth-wasteful, latency-bound on cursor pagination, and wrong-by-construction once the underlying row count exceeds the per-page cap (1000): clients can only aggregate a sample.

This change adds a small, deliberate set of aggregation endpoints that compute the answer on the server, returning a compact result that fits comfortably in a single response. The endpoints reuse every existing predicate and time-window machinery — they accept the same filter set as the corresponding GET search — and they cap the cardinality of group-by keys so a single request cannot DoS the scanner.

Aggregations are read-only, idempotent, and shape-stable, so the OTLP-faithful single-event lookups (`GET /v1/traces/{traceId}`) stay untouched.

## What Changes

- New endpoint per signal: `GET /v1/{signal}/aggregate?function=…&groupBy=…&filter parameters from existing GET search&interval=…&since=…&until=…`. Body shape, pagination, and content negotiation mirror the corresponding GET search.
- Supported functions in v1:
  - `count` — count of matching rows. Universally applicable.
  - `sum` of a numeric column — applies where the underlying column exists (`http_response_status_code` on traces; `severity_number` on logs as a degenerate case but supported for completeness; `value_double` / `value_int` / `count` / `sum` on metrics).
  - `avg` of a numeric column — same domain as `sum`.
  - `min`, `max` of a numeric column — same domain.
  - `p50`, `p90`, `p95`, `p99` of a numeric column — quantile estimates over the matching rows.
- Optional `groupBy` parameter accepting a comma-separated list of typed column names: `service`, `environment`, `host`, `metricName`, `kind`, `statusCode`, etc. (a closed allow-list per signal). Group-by emits one result row per distinct group key combination.
- Optional `interval` parameter (`1m`, `5m`, `15m`, `1h`, `1d`) that buckets the result by time. When set, every result row carries `bucket_start_unix_nano`. Missing → ungrouped over the time window.
- Caps:
  - `crashler.read.aggregate.max_groups` (default 200): if the cardinality exceeds the cap, return HTTP 400 with a message asking the client to filter further or reduce the group-by.
  - `crashler.read.aggregate.max_intervals` (default 720) — about 24 hours of 2-minute buckets.
  - `crashler.read.execution_timeout_seconds` (existing 10) governs scanner time.
- Response shape: a list of result rows, each carrying `{group: {service: 'checkout', ...}, bucket_start_unix_nano: ..., function: 'p99', value: 312.4, sample_count: 1242}`. Format negotiation across jsonld / hal / compact / jsonapi as elsewhere.
- `App\Read\Compute\AggregatingScanner`: a sibling to `ParquetScanner` that runs the same predicate evaluation but accumulates into a per-group state machine instead of materialising rows.
- `App\Read\Compute\Aggregations\*` set of pure functions: count accumulator, sum/avg/min/max scalar accumulator, t-digest-based quantile accumulator (a pure-PHP implementation; small footprint, well-understood).
- Documentation: README "Aggregations" subsection with examples; OpenAPI describes the endpoint per the new examples global rule.

## Capabilities

### New Capabilities

- `read-aggregations`: defines the aggregate endpoint family, the supported functions, the group-by + interval semantics, the caps, and the result shape.

### Modified Capabilities

- `read-api`: adds a one-paragraph reference pointing at the new `read-aggregations` capability so the read-API capability stays a coherent index.

## Impact

- New code:
  - 1 new capability file.
  - 1 `#[GetCollection]` operation per Resource at `uriTemplate: '/v1/<plural>/aggregate'` with a custom processor.
  - 1 `App\Read\Compute\AggregatingScanner` (~150 lines).
  - 1 set of accumulator classes (~250 lines including the t-digest).
  - 1 `App\Read\Resource\Aggregate*Result` DTO per signal (or one shared DTO if shapes align).
  - Tests: per-function correctness, group-by cardinality cap, interval bucketing, time-window propagation, predicate composition.
- New config: `crashler.read.aggregate.max_groups`, `crashler.read.aggregate.max_intervals`. Existing caps reused.
- No backward-incompatible behaviour. GET search and Trace.Get/Span.Get are untouched.
- No new dependencies. The t-digest is implemented in-tree; alternatives like external quantile sketches require a runtime that is not on All-Inkl shared hosting.
- Operational risk: aggregations are CPU-intensive on wide time windows. → Mitigation: the existing time-window cap and execution timeout already bound the worst case; the `max_groups` cap bounds memory.
