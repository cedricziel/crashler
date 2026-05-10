## Why

The six read-API changes archived on 2026-05-09 (post-search, rowgroup-pushdown, multi-attribute-filters, api-spec-examples, aggregations, compat-shims) each shipped a working v1 slice with a clear scope cut. Every cut was deliberate — bounded scope per change, working code first, lavish coverage second — but the cuts add up. This umbrella change catalogs every deferred item from those six archives into one navigable document so the cuts don't get lost in the per-change archive trees.

The umbrella is intentionally a tracker, not a prescriptive roadmap. Each theme below sketches the work; when a theme is prioritized for implementation, it splits into its own change with proposal/design/tasks following the standard `/opsx:propose` pattern. Some themes may merge with newer requirements; some may be re-scoped or dropped entirely. The point is to keep the deferred items visible.

## What Changes

This change does not add or modify any capability. Its purpose is to enumerate the deferred work and capture the thematic groupings so future `/opsx:propose` invocations can pick a theme and turn it into its own change.

The five thematic follow-ups identified across the six archived changes:

- **`add-read-aggregations-percentiles`** — Quantile estimation and time bucketing for the aggregate endpoints. Adds: t-digest accumulator, p50/p90/p95/p99 functions, `interval` bucketing with `max_intervals` cap, `_links.search` drill-down affordance, traces/metrics functional tests, cardinality-cap functional test, README "Aggregations" subsection.
- **`add-read-compat-shims-querying`** — Real query support behind the Grafana compat shims. Adds: Tempo `/api/search` + `/api/traces/{traceId}` (with TraceQL rejection), Loki `/api/v1/label/{name}/values` + `/api/v1/query_range` (with selector + line-filter LogQL subset), Prometheus `/api/v1/label/{name}/values` + `/api/v1/query_range` (with `count_over_time` + `sum by` PromQL subset), shared abstract controller + response shaper + error envelope helpers, cross-tenancy test, README "Grafana compatibility" subsection, `docs/grafana-datasources.example.yaml`.
- **`add-read-openapi-bodies`** — Operation-level OpenAPI examples beyond the per-parameter `example` shipped in v1. Adds: per-format response body examples, named `examples` maps (simple + medium-complex), POST search request-body OpenAPI exposure, lint extensions for the operation-level rule, lint-failure unit tests, CI workflow step, README "Examples on the spec" subsection, CONTRIBUTING.md note.
- **`add-read-test-coverage-roundup`** — Test gap-fills from the v1 changes. Adds: dedicated time-window row-group push-down test (multi-hour fixture across partitions), unit test for predicates referencing absent columns, multi-attribute filter functional tests for traces and metrics, body-size 413 functional test for POST search, metrics `exemplarTraceId` sugar test, traces/metrics aggregate functional tests, aggregate cardinality-cap functional test, compat cross-tenancy test.
- **`add-read-docs-roundup`** — README and contributor-guide expansion deferred from individual changes. Adds: README "Aggregations" subsection (count/sum/p99 worked examples + cap operator advice), README "Grafana compatibility" subsection (per-shim flags + non-preservations), `docs/grafana-datasources.example.yaml`, CONTRIBUTING.md note about the OpenAPI examples rule for new endpoints.

## Capabilities

### New Capabilities

(none — this change tracks deferred work; no spec deltas)

### Modified Capabilities

(none — see above)

## Impact

- No code changes. No spec deltas. No tests.
- Provides a single touchpoint for "what didn't ship in the 2026-05-09 read-API batch and why".
- Each theme is self-contained and can be picked up independently when prioritized.
- The `tasks.md` for this change lists every deferred item with a pointer to its archived parent and the original task ID, so the historical trail is complete.
