## 1. Theme: add-read-aggregations-percentiles

- [ ] 1.1 Implement t-digest accumulator (Dunning's algorithm, pure PHP, default compression 100). Reference: `add-read-aggregations` deferred items 2.3, 2.4
- [ ] 1.2 Add p50 / p90 / p95 / p99 functions to `AccumulatorFactory::SUPPORTED_FUNCTIONS`. Reference: `add-read-aggregations` 2.4
- [ ] 1.3 Add `interval` parameter (`1m`/`5m`/`15m`/`1h`/`1d`) on the aggregate controllers; bucket results by UTC-aligned timestamp floor. Reference: `add-read-aggregations` 3.5
- [ ] 1.4 Add `crashler.read.aggregate.max_intervals` cap (default 720); pre-compute bucket count, reject over-cap before scanning. Reference: `add-read-aggregations` 1.2, 3.5
- [ ] 1.5 Add `_links.search` drill-down to aggregate response shape. Reference: `add-read-aggregations` 5.1, 5.2, 6.8
- [ ] 1.6 t-digest unit tests against known datasets (uniform, exponential, bimodal). Reference: `add-read-aggregations` 6.2
- [ ] 1.7 Functional tests for traces and metrics aggregate endpoints. Reference: `add-read-aggregations` 6.4, 6.5
- [x] 1.8 [PROMOTED to add-read-test-coverage-roundup §6] Cardinality cap functional test
- [ ] 1.9 Interval cap functional test (over-cap window+interval combo). Reference: `add-read-aggregations` 6.7

## 2. Theme: add-read-compat-shims-querying

- [ ] 2.1 Tempo `GET /api/search` with `tags` / `service.name` / `min/maxDuration` / `start` / `end` / `limit` parameters; map to predicate compiler. Reference: `add-read-compat-shims` 3.2
- [ ] 2.2 Tempo `GET /api/traces/{traceId}` delegating to existing `ReadTraceController`. Reference: `add-read-compat-shims` 3.3
- [ ] 2.3 `TempoLogQLParser` for the `tags=` mini-DSL (regex-driven). Reference: `add-read-compat-shims` 3.4
- [ ] 2.4 Tempo TraceQL rejection (with informative error). Reference: `add-read-compat-shims` originating spec scenario
- [ ] 2.5 Loki `GET /api/v1/label/{name}/values` (partition scan for distinct values within window). Reference: `add-read-compat-shims` 4.2
- [ ] 2.6 Loki `GET /api/v1/query_range` with `LogQLSubsetParser` (selector + line filter only; reject regex selectors and aggregations). Reference: `add-read-compat-shims` 4.3
- [ ] 2.7 Prometheus `GET /api/v1/label/{name}/values`. Reference: `add-read-compat-shims` 5.2
- [ ] 2.8 Prometheus `GET /api/v1/query_range` with `PromQLSubsetParser` (raw selector | `count_over_time` | `sum by`). Delegates to aggregation primitives. Reference: `add-read-compat-shims` 5.3
- [ ] 2.9 Shared `AbstractShimController` extracting auth/tenancy/timeout helpers. Reference: `add-read-compat-shims` 2.1
- [ ] 2.10 Shared response shaper + per-vendor error envelope helper. Reference: `add-read-compat-shims` 2.2, 2.3
- [ ] 2.11 Cross-tenancy functional test (search results for tenant A do not surface tenant B's data). Reference: `add-read-compat-shims` 7.2

## 3. Theme: add-read-openapi-bodies

- [ ] 3.1 Per-format response body examples on each read operation (Hydra `member`, HAL `_embedded`, JSON:API `data`, compact JSON top-level). Reference: `add-read-api-spec-examples` 1.2
- [ ] 3.2 Operation-level named `examples` maps with `simple` + `complex` entries. Reference: `add-read-api-spec-examples` 3.5
- [ ] 3.3 POST search request-body OpenAPI exposure (currently the POST search controllers are plain controllers with no AP4 declaration). Reference: `add-read-api-spec-examples` 3.6
- [ ] 3.4 Lint extension: assert "≥2 named examples per operation" rule passes. Reference: `add-read-api-spec-examples` 3.5
- [ ] 3.5 Lint extension: assert POST request-body example presence on operations with bodies. Reference: `add-read-api-spec-examples` 3.6
- [ ] 3.6 Synthetic-spec unit tests for missing-example detection (negative path coverage). Reference: `add-read-api-spec-examples` 4.2
- [ ] 3.7 Out-of-scope path test (writes pass through unflagged). Reference: `add-read-api-spec-examples` 4.3
- [ ] 3.8 Wire CI workflow step running `bin/console app:openapi:lint-examples`. Reference: `add-read-api-spec-examples` 3.8

## 4. Theme: add-read-test-coverage-roundup

- [x] 4.1 [PROMOTED to add-read-test-coverage-roundup §1] Dedicated time-window row-group push-down test
- [x] 4.2 [PROMOTED to add-read-test-coverage-roundup §2] Schema-absent column unit test
- [x] 4.3 [PROMOTED to add-read-test-coverage-roundup §3] Multi-attribute traces functional test
- [x] 4.4 [PROMOTED to add-read-test-coverage-roundup §4] Multi-attribute metrics functional test
- [x] 4.5 [PROMOTED to add-read-test-coverage-roundup §5] POST search body-size 413 test
- [~] 4.6 [DROPPED] Metrics `exemplarTraceId` sugar test — the sugar wasn't shipped in `add-read-post-search` v1; this test depends on a feature that hasn't shipped. Tracked under a future POST-search-extensions follow-up if/when the sugar is implemented

## 5. Theme: add-read-docs-roundup

- [ ] 5.1 README "Aggregations" subsection with worked examples (count/sum/p99/groupBy/interval) and operator advice on caps. Reference: `add-read-aggregations` 7.1, 7.2
- [ ] 5.2 README "Grafana compatibility" subsection: per-shim flags, supported endpoints, explicit non-preservations, version pins. Reference: `add-read-compat-shims` 8.1, 8.3
- [ ] 5.3 `docs/grafana-datasources.example.yaml` — provisioning snippet pointing Grafana data sources at the shim paths. Reference: `add-read-compat-shims` 8.2
- [ ] 5.4 README "Examples on the spec" subsection pointing at `/docs` Swagger UI's example dropdown. Reference: `add-read-api-spec-examples` 5.1
- [ ] 5.5 CONTRIBUTING.md note: every new read-API endpoint must declare parameter examples and pass `app:openapi:lint-examples`. Reference: `add-read-api-spec-examples` 5.2

## 6. Promotion

When a theme is prioritized for implementation, the workflow is:

- [ ] 6.1 Run `/opsx:propose <theme-name>` (e.g., `add-read-aggregations-percentiles`)
- [ ] 6.2 Copy this theme's tasks into the new change's tasks.md as the starting point
- [ ] 6.3 In this umbrella's tasks.md, mark the absorbed items `[x] [PROMOTED to <theme>]` so the trail is preserved
- [ ] 6.4 When all themes have been promoted (or explicitly dropped), archive this umbrella with `/opsx:archive add-read-deferred-followups`
