**Methodology.** Strict red-green-refactor. Every implementation task is preceded by a failing test for the smallest meaningful behaviour. `[red]` writes a failing test; `[green]` makes it pass with the minimum code; refactor cycles are guarded by the existing test suite remaining green without test-body changes.

The read path reuses the auth scaffolding (`IngestTokenAuthenticator`, `Tenant`), the schema catalog, the partition path resolver (read direction), and the same Symfony firewall pattern (`^/v1/`). It introduces a parallel pipeline (`ReadPipeline`) that mirrors the structure of the write side `OtlpRequestPipeline` so per-signal controllers remain paper-thin.

## 1. Read configuration + boot-time engine selection

- [ ] 1.1 [red] Component test: kernel boot sets the active read executor service ID based on `crashler.read.compute_engine` (auto / duckdb / flow-php) and DuckDB binary availability
- [ ] 1.2 Add config keys: `crashler.read.compute_engine`, `crashler.read.max_time_window_days`, `crashler.read.max_page_size`, `crashler.read.cursor_secret`, `crashler.read.span_lookup_window_hours` (default 24)
- [ ] 1.3 [green] Implement `App\Read\Compute\ExecutorFactory::resolve()` doing PATH lookup / `CRASHLER_DUCKDB_BIN` resolution and returning a `WhichExecutor` enum or service alias
- [ ] 1.4 [red] Test: `compute_engine=duckdb` with no binary → kernel boot fails with a clear error
- [ ] 1.5 [green] Wire the fail-fast behavior

## 2. ReadPipeline scaffolding (TDD)

- [ ] 2.1 [red] Unit test: `ReadPipeline::handle(Request, IngestUser, CriteriaParser, Executor, ResponseBuilder)` returns a `JsonResponse` with `Content-Type: application/json`
- [ ] 2.2 [green] Implement the skeleton — auth is already enforced by the firewall; pipeline does only criteria→executor→response mapping
- [ ] 2.3 [red] Test: 4xx returned by the criteria parser surfaces unchanged (parser owns its own error envelope)
- [ ] 2.4 [red] Test: executor throwing → 500 with operator-friendly message, no stack trace, no DuckDB output, no absolute path leakage
- [ ] 2.5 [green] Wire the executor-error mapping

## 3. Time window parsing + partition pruning

- [ ] 3.1 [red] Unit test: `TimeWindow::parse(['since'=>null,'until'=>null])` defaults to `[now-1h, now]`
- [ ] 3.2 [red] Unit test: `since=2h` resolves to `[now-2h, now]`; supports `m`, `h`, `d` suffixes
- [ ] 3.3 [red] Unit test: `since=2026-05-09T13:00:00Z&until=2026-05-09T14:00:00Z` parses both as RFC3339
- [ ] 3.4 [red] Unit test: `since=1714752000000000000` parses as unix-nano numeric string
- [ ] 3.5 [red] Unit test: window > `max_time_window_days` (default 30d) → `OutOfRangeException` with the configured cap in the message
- [ ] 3.6 [red] Unit test: `until < since` rejected
- [ ] 3.7 [green] Implement `App\Read\Criteria\TimeWindow`
- [ ] 3.8 [red] Unit test: `PartitionPruner::globsFor(tenant, signal, window)` returns only the `date=…/hour=…` partition path globs that fall inside the window
- [ ] 3.9 [green] Implement `App\Read\Compute\PartitionPruner`
- [ ] 3.10 [red] Component test: pruner against a synthetic tenant tree with 3 days of partitions returns only the matching ones

## 4. Cursor (encode + sign + decode + verify)

- [ ] 4.1 [red] Unit test: `Cursor::mint(criteriaArray, position, tenantSlug, secret)` returns an opaque base64url string
- [ ] 4.2 [red] Unit test: `Cursor::decode(opaque, secret)` round-trips and returns the original `(criteriaArray, position, tenantSlug)`
- [ ] 4.3 [red] Unit test: `Cursor::decode` throws `InvalidCursorException` on tampered payload
- [ ] 4.4 [red] Unit test: `Cursor::decode` throws when the embedded tenantSlug ≠ the current request's tenantSlug
- [ ] 4.5 [red] Unit test: `Cursor::decode` throws when the embedded criteria's `since`/`until` exceed the configured cap (defense-in-depth)
- [ ] 4.6 [green] Implement `App\Read\Cursor` (HMAC-SHA256, base64url, no padding)
- [ ] 4.7 [red] Unit test: secret rotation invalidates pre-rotation cursors with a clear message

## 5. Common criteria parser (URL params → typed criteria)

- [ ] 5.1 [red] Unit test: `CommonCriteriaParser` accepts `service`, `environment`, `host`, `since`, `until`, `limit`, `cursor`
- [ ] 5.2 [red] Unit test: unknown parameter throws `UnknownCriterionException` listing the supported ones for the signal
- [ ] 5.3 [red] Unit test: `limit > max_page_size` rejected with the cap value in the message
- [ ] 5.4 [red] Unit test: `limit` defaults to 100 when absent
- [ ] 5.5 [red] Unit test: when `cursor` is provided, the parser resolves the criteria from the cursor and IGNORES other query params (cursor is the source of truth)
- [ ] 5.6 [green] Implement `App\Read\Criteria\CommonCriteriaParser`

## 6. Compute engine: DuckDB executor

- [ ] 6.1 [red] Component test (skipped if DuckDB binary missing): `DuckDbExecutor::execute(criteria, partitionGlobs)` returns rows from a synthetic Parquet fixture
- [ ] 6.2 [green] Implement `App\Read\Compute\DuckDbExecutor` shelling out to DuckDB with a parameterised SELECT
- [ ] 6.3 [red] Test: criteria are parameterised — a value `' OR 1=1; --` returns zero rows (no SQL injection possible because tenant slug enters as path glob, criteria values enter as DuckDB prepared parameters)
- [ ] 6.4 [red] Test: predicate push-down works — query timing on a 100-MB synthetic fixture with a service filter is bounded (sanity check, not a hard SLA)
- [ ] 6.5 [red] Test: stderr from DuckDB is captured and surfaced as a 5xx with operator-friendly message, no raw stderr leak
- [ ] 6.6 [red] Test: DuckDB nonzero exit → `ExecutorException`
- [ ] 6.7 [red] Test: result rows decode integers from DuckDB JSON correctly (int64 → string-as-decimal)

## 7. Compute engine: flow-php fallback

- [ ] 7.1 [red] Component test: `FlowPhpExecutor::execute(criteria, partitionGlobs)` returns the same rows as DuckDB on a fixture
- [ ] 7.2 [green] Implement `App\Read\Compute\FlowPhpExecutor` streaming Parquet rows via flow-php and applying filters in PHP
- [ ] 7.3 [red] Test: large fixture (10 K rows) does not load all rows into memory at once (peak memory bounded)
- [ ] 7.4 [red] Test: equivalence with DuckDB on the same fixture — same row set, same column values

## 8. Logs query (TDD)

- [ ] 8.1 [red] Unit test: `LogsCriteriaParser` accepts `severityNumber`, `severityNumberMin`, `severityText`, `traceId`, `spanId`, `eventName`, `bodyContains`, `attribute.<key>` plus the common params
- [ ] 8.2 [red] Test: traceId of wrong length (e.g. 4 chars) → 400 with `traceId` in the message
- [ ] 8.3 [red] Test: two `attribute.*` filters in one request → 400 noting the v1 limit
- [ ] 8.4 [green] Implement `App\Read\Criteria\LogsCriteriaParser`
- [ ] 8.5 [red] Functional test (`zenstruck/browser`): `GET /v1/logs?service=checkout&since=1h&limit=5` with valid bearer → 200, `schemaId=logs/v1`, ≤5 rows, every row's `resourceServiceName==checkout`
- [ ] 8.6 [green] Implement `App\Controller\ReadLogsController` and its services.yaml wiring
- [ ] 8.7 [red] Functional test: missing bearer → 401
- [ ] 8.8 [red] Functional test: tenant `acme` cannot see tenant `widgets` data even with valid `acme` token
- [ ] 8.9 [red] Functional test: `_links.self` is set; `_links.next` is set when more results exist; absent on last page
- [ ] 8.10 [red] Functional test: row with `traceIdHex` set carries `_links.trace = /v1/traces/<hex>`
- [ ] 8.11 [red] Functional test: row with `traceIdHex==null` does NOT carry `_links.trace`
- [ ] 8.12 [red] Functional test: `traceId=<hex>&since=...&until=...` (the trace-link target) returns only that trace's logs
- [ ] 8.13 [red] Functional test: `severityNumberMin=17` filters out severity < 17

## 9. Traces query (TDD)

- [ ] 9.1 [red] Unit test: `TracesCriteriaParser` accepts `name`, `kind`, `statusCode`, `httpStatusCodeMin`, `traceId`, `parentSpanId`, `attribute.<key>` plus the common params
- [ ] 9.2 [red] Test: `name=GET+/orders/*` parses with trailing wildcard
- [ ] 9.3 [red] Test: `kind=BANANA` rejected with supported values listed
- [ ] 9.4 [green] Implement `App\Read\Criteria\TracesCriteriaParser`
- [ ] 9.5 [red] Functional test: `GET /v1/traces?service=checkout&kind=SERVER&since=1h` → matching rows; per-row `_links.trace` set
- [ ] 9.6 [red] Unit test: `TraceTreeAssembler::assemble(rows)` groups rows back into ResourceSpans → ScopeSpans → spans, preserving order
- [ ] 9.7 [green] Implement `App\Read\Traces\TraceTreeAssembler`
- [ ] 9.8 [red] Functional test: `GET /v1/traces/<hex>` returns `{resourceSpans:[...], _links:{...}}`; spans grouped under their resource/scope
- [ ] 9.9 [red] Functional test: trace-by-ID `_links.logs = /v1/logs?traceId=<id>&since=<start>&until=<end>`
- [ ] 9.10 [red] Functional test: trace-by-ID `_links.metricsWithExemplars = /v1/metrics?exemplarTraceId=<id>&since=<start>&until=<end>`
- [ ] 9.11 [red] Functional test: missing trace ID → 404 with the searched window in the message
- [ ] 9.12 [red] Functional test: malformed trace ID (non-hex / wrong length) → 400
- [ ] 9.13 [red] Functional test: `GET /v1/spans/<hex>` returns `{span:{...}, _links:{trace, logs}}`
- [ ] 9.14 [red] Functional test: span-by-ID `_links.trace = /v1/traces/<traceIdOfThatSpan>`
- [ ] 9.15 [green] Implement `App\Controller\ReadTracesController` and `App\Controller\ReadSpansController`

## 10. Metrics query (TDD)

- [ ] 10.1 [red] Unit test: `MetricsCriteriaParser` accepts `metricName`, `metricType`, `aggregationTemporality`, `exemplarTraceId`, `attribute.<key>` plus the common params
- [ ] 10.2 [red] Test: `metricType=BANANA` rejected
- [ ] 10.3 [red] Test: wildcard in `metricName` rejected with v1-not-supported message
- [ ] 10.4 [green] Implement `App\Read\Criteria\MetricsCriteriaParser`
- [ ] 10.5 [red] Functional test: `GET /v1/metrics?service=checkout&metricType=HISTOGRAM&since=1h` → matching rows with `metricType==HISTOGRAM`
- [ ] 10.6 [red] Functional test: row with non-empty `exemplarsJson` carrying a traceId → `_links.exemplars = /v1/traces/<first-exemplar-hex>`
- [ ] 10.7 [red] Functional test: row with `exemplarsJson==[]` does NOT carry `_links.exemplars`
- [ ] 10.8 [red] Functional test: `exemplarTraceId=<hex>` returns only metrics whose exemplars reference that trace
- [ ] 10.9 [green] Implement `App\Controller\ReadMetricsController`

## 11. HATEOAS link builder (shared)

- [ ] 11.1 [red] Unit test: `LinkBuilder::self(currentUrl)` returns the request URL with the resolved time window made explicit (not the duration shorthand)
- [ ] 11.2 [red] Unit test: `LinkBuilder::next(criteria, lastPosition)` returns a URL containing a fresh cursor
- [ ] 11.3 [red] Unit test: `LinkBuilder::trace(traceIdHex)` and `::span(spanIdHex)` produce well-formed paths
- [ ] 11.4 [red] Unit test: `LinkBuilder::exemplars(exemplarsJson)` returns the URL for the first traceId entry, or null for `[]`
- [ ] 11.5 [green] Implement `App\Read\Hateoas\LinkBuilder`
- [ ] 11.6 [red] Component test: a logs row with attribute filtering applied still produces correct cross-signal links — link generation is independent of which criteria led to the match

## 12. Cursor pagination end-to-end

- [ ] 12.1 [red] Functional test: page through 250 rows with `limit=100` → 3 pages, `_links.next` chain, last page omits `next`
- [ ] 12.2 [red] Functional test: `_links.next` URL between pages re-uses the original `since`/`until` even if the original request used `since=1h` shorthand (cursor encodes resolved instants)
- [ ] 12.3 [red] Functional test: tampered cursor → 400
- [ ] 12.4 [red] Functional test: cursor minted for tenant `acme` rejected when presented by tenant `widgets` (despite valid bearer)
- [ ] 12.5 [red] Functional test: rotating `cursor_secret` invalidates outstanding cursors

## 13. Cross-signal navigation end-to-end

- [ ] 13.1 [red] Functional test: ingest 1 trace + matching logs + matching metrics. `GET /v1/logs?...` → follow `_links.trace` from a row → 200 with that trace tree → follow `_links.metricsWithExemplars` → 200 with the metric rows linking back
- [ ] 13.2 [red] Functional test: a metric exemplar's `_links.exemplars` resolves to a real `/v1/traces/<hex>` that 200s
- [ ] 13.3 [red] Functional test: trace-by-ID's `_links.logs` resolves to a `/v1/logs?...` that 200s and returns logs from that trace

## 14. Operator documentation

- [ ] 14.1 README: new "Reading data" section before the existing "Querying" (DuckDB) section. Cover all five endpoints, the criteria, the `_links` model, and how to follow links from `curl + jq`
- [ ] 14.2 README: document the `crashler.read.*` config keys and the DuckDB-binary install path on All-Inkl
- [ ] 14.3 README: re-frame the existing "Querying" (DuckDB) section as "Operator/debug recipes (not a public interface)"

## 15. Spec scenario cross-check

- [ ] 15.1 Walk every `#### Scenario:` block in `specs/read-api/spec.md` and confirm a unit/component/functional test covers it
- [ ] 15.2 Walk every scenario in `specs/logs-query/spec.md`, `specs/traces-query/spec.md`, `specs/metrics-query/spec.md` and confirm coverage
- [ ] 15.3 Add tests for any unmapped scenario; capture the coverage map inline (mirrors the previous changes' audit tables)

## 16. Final validation + deploy

- [ ] 16.1 `composer test` passes with zero deprecations/notices/warnings across all three suites
- [ ] 16.2 `openspec validate add-otlp-read-api --strict` passes
- [ ] 16.3 CI green on main
- [ ] 16.4 Deployer: ensure the production host has DuckDB at `<deploy_path>/shared/bin/duckdb` (one-time setup task or operator step), set `CRASHLER_DUCKDB_BIN` accordingly
- [ ] 16.5 `dep deploy stage=production` (additive, no env flag, no schema-breaking purge). Smoke test all three search endpoints + a trace-by-id and confirm 200s, schemaId markers, and follow-the-link works
- [ ] 16.6 Optional: run a Grafana datasource (or curl-only) end-to-end against `https://crashler.cedric-ziel.com/v1/{logs,traces,metrics}` to validate over the real network
