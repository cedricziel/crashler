**Methodology.** Strict red-green-refactor. Every implementation task is preceded by a failing test for the smallest meaningful behaviour. `[red]` writes a failing test; `[green]` makes it pass with the minimum code; refactor cycles are guarded by the existing test suite remaining green without test-body changes.

The read path reuses the auth scaffolding (`IngestTokenAuthenticator`, `Tenant`), the schema catalog, the partition path resolver (read direction), and the same Symfony firewall pattern (`^/v1/`). API Platform is the framework for routing, content negotiation, filter parsing, OpenAPI generation, hypermedia rendering, and pagination plumbing. State Providers per signal are the only logic layer we own — they translate filter context + tenant into a streaming `ParquetScanner` call.

## 1. API Platform installation + read-side configuration

- [x] 1.1 `composer require api-platform/symfony` and confirm the recipe runs (creates `config/packages/api_platform.yaml`)
- [x] 1.2 Override `api_platform.route_prefix` to `/v1` so resource routes land at `/v1/<plural>` instead of the default `/api/<plural>`
- [x] 1.3 Configure the supported `formats` list: `jsonld` (default), `hal`, `json` (compact), `jsonapi`, plus `otlp+json` (registered for the Trace.Get operation only — see §10)
- [x] 1.4 Configure the OpenAPI title, description, and bearer-token security scheme so it shows up at `/api/docs.json` and `/api/docs`
- [x] 1.5 Add config keys to `CrashlerExtension`: `crashler.read.max_time_window_days` (30), `crashler.read.max_page_size` (1000), `crashler.read.cursor_secret` (sourced from `APP_SECRET`), `crashler.read.span_lookup_window_hours` (24), `crashler.read.execution_timeout_seconds` (10)
- [x] 1.6 [red] Component test: kernel boot exposes the keys as DI parameters with documented defaults
- [x] 1.7 [green] Wire the parameters
- [x] 1.8 [red] Test: missing/empty `cursor_secret` fails boot with a clear message
- [x] 1.9 [red] Component test: Swagger UI is reachable at `/api/docs` (200) and `/api/docs.json` returns valid OpenAPI 3.1 JSON

## 2. Time window parsing + partition pruning (TDD)

- [x] 2.1 [red] Unit test: `TimeWindow::parse(['since'=>null,'until'=>null])` defaults to `[now-1h, now]`
- [x] 2.2 [red] Unit test: `since=2h` resolves to `[now-2h, now]`; supports `m`, `h`, `d` suffixes
- [x] 2.3 [red] Unit test: `since=2026-05-09T13:00:00Z&until=2026-05-09T14:00:00Z` parses both as RFC3339
- [x] 2.4 [red] Unit test: `since=1714752000000000000` parses as unix-nano numeric string
- [x] 2.5 [red] Unit test: window > `max_time_window_days` (30d) → `OutOfRangeException` with cap in message
- [x] 2.6 [red] Unit test: `until < since` rejected
- [x] 2.7 [red] Unit test: `since=2h&until=<absolute>` (mixed semantics) rejected
- [x] 2.8 [green] Implement `App\Read\Criteria\TimeWindow`
- [x] 2.9 [red] Unit test: `PartitionPruner::globsFor(tenant, signal, window)` returns only the `date=…/hour=…` partition path globs that fall inside the window
- [x] 2.10 [green] Implement `App\Read\Compute\PartitionPruner`
- [x] 2.11 [red] Component test: pruner against a synthetic tenant tree with 3 days of partitions returns only the matching ones

## 3. Predicate primitives (TDD)

- [x] 3.1 [red] Unit test: `ColumnEquals('name', 'foo')` matches a row map where `row['name'] === 'foo'`, fails otherwise; null-safe
- [x] 3.2 [red] Unit test: `ColumnGreaterEqual('severity_number', 17)` matches rows with severity ≥ 17; rejects rows with null severity
- [x] 3.3 [red] Unit test: `ColumnInRange('time_unix_nano', $low, $high)` matches inclusive of both bounds
- [x] 3.4 [red] Unit test: `ColumnLikePrefix('name', 'GET ')` and `ColumnLikeSuffix('name', '.duration')`
- [x] 3.5 [red] Unit test: `JsonStringContains('body_json', 'connection refused')` does a `strpos`
- [x] 3.6 [red] Unit test: `JsonAttributeEquals('attributes_json', 'exception.type', 'RuntimeException')` decodes the JSON and walks the array; matches only the structurally correct entry; defends against substring false-positives (e.g., a value of `"foo exception.type"` does NOT match)
- [x] 3.7 [red] Unit test: `JsonAttributeEquals` on a row whose JSON column is `[]` returns false
- [x] 3.8 [red] Unit test: `JsonAttributeEquals` on malformed JSON returns false (defensive — corrupt rows skip silently rather than throwing)
- [x] 3.9 [green] Implement all primitives in `App\Read\Compute\Predicates\*`

## 4. Cursor (encode + sign + decode + verify) (TDD)

- [ ] 4.1 [red] Unit test: `Cursor::mint(criteriaArray, position, tenantSlug, secret)` returns an opaque base64url string
- [ ] 4.2 [red] Unit test: `Cursor::decode(opaque, secret)` round-trips and returns the original `(criteriaArray, position, tenantSlug)`
- [ ] 4.3 [red] Unit test: `Cursor::decode` throws `InvalidCursorException` on tampered payload
- [ ] 4.4 [red] Unit test: `Cursor::decode` throws when the embedded tenantSlug ≠ the current request's tenantSlug
- [ ] 4.5 [red] Unit test: `Cursor::decode` throws when the embedded criteria's `since`/`until` exceed the configured cap (defense-in-depth)
- [ ] 4.6 [green] Implement `App\Read\Cursor` (HMAC-SHA256, base64url, no padding)
- [ ] 4.7 [red] Unit test: secret rotation invalidates pre-rotation cursors with a clear message

## 5. ParquetScanner (TDD)

- [ ] 5.1 [red] Component test: `ParquetScanner::scan(predicates, partitionGlobs, limit)` returns rows from a synthetic single-file Parquet fixture
- [ ] 5.2 [green] Implement `App\Read\Compute\ParquetScanner` opening files via flow-php's `Reader`, streaming rows, evaluating predicates in tier order
- [ ] 5.3 [red] Test: tier-ordered evaluation — when `service=foo` rejects 99% of rows, the JSON-attribute predicate's `json_decode` is called only on the surviving 1% (verified by counting json_decode calls or by timing comparison)
- [ ] 5.4 [red] Test: row-group statistics push-down — when a row group's `max(severity_number) = 9` and the predicate is `ColumnGreaterEqual('severity_number', 17)`, the scanner does NOT iterate that group's data pages
- [ ] 5.5 [red] Test: large fixture (10 K rows) does NOT load all rows into memory; peak memory bounded by `limit` × per-row size
- [ ] 5.6 [red] Test: ULID-ordered file iteration — three files with ULIDs A < B < C in the same partition are read in A, B, C order
- [ ] 5.7 [red] Test: early-exit on `limit` — partition with 10 K matching rows + `limit=100` reads at most enough row groups to surface 100 rows
- [ ] 5.8 [red] Test: scanner respects `execution_timeout_seconds` and surfaces a `ScanTimeoutException`
- [ ] 5.9 [red] Test: a corrupt Parquet file in the partition surfaces as `ScanIoException` with the partition path masked (no `var/` leakage)
- [ ] 5.10 [red] Test: integers from Parquet's INT64 columns serialize as JSON strings (preserves int64 precision; mirrors OTLP/HTTP-JSON convention)
- [ ] 5.11 [red] Test: round-trip — write fixture data via `ParquetFileWriter`, read it back via `ParquetScanner`; rows match exactly

## 6. AP State Providers + filter framework (TDD)

- [ ] 6.1 [red] Unit test: `App\Read\State\BaseStateProvider` parses the AP `Operation` + filters context into a `(predicates, window, paginationCursor, tenantSlug)` tuple
- [ ] 6.2 [red] Test: BaseStateProvider rejects an authenticated user whose tenant differs from the cursor's embedded tenant (returns 400)
- [ ] 6.3 [green] Implement `BaseStateProvider`
- [ ] 6.4 [red] Test: AP's filter framework integration — `App\Read\Filter\TimeRangeFilter`, `ServiceFilter`, `EnvironmentFilter`, `HostFilter`, `LimitFilter`, `CursorFilter` declare their OpenAPI contributions correctly
- [ ] 6.5 [green] Implement the common filter classes in `App\Read\Filter\*`
- [ ] 6.6 [red] Test: unknown query parameter on a Resource → AP's filter framework returns 400 with the supported-list (default AP behavior; the test asserts our config doesn't disable it)
- [ ] 6.7 [red] Test: repeated query parameter (`?service=foo&service=bar`) → 400 with named-parameter message
- [ ] 6.8 [green] Configure / hook AP to enforce repeated-param rejection

## 7. Cursor pagination integration with API Platform (TDD)

- [ ] 7.1 [red] Test: `App\Read\Cursor\CursorPaginator` implements AP's pagination contract and exposes `getCurrentPage`/`getItemsPerPage`/`getTotalItems`/iterator
- [ ] 7.2 [red] Test: When the scanner indicates more rows exist, the paginator emits a next-cursor; AP renders it as `hydra:next` (Hydra), `_links.next` (HAL/json), `links.next` (jsonapi)
- [ ] 7.3 [red] Test: When the scanner exhausts results within `limit`, the paginator emits no next-cursor; the response carries no next affordance
- [ ] 7.4 [green] Implement `CursorPaginator` and wire into the state providers
- [ ] 7.5 [red] Functional test: page through 250 rows with `limit=100` → 3 pages, next-affordance chain, last page omits next
- [ ] 7.6 [red] Functional test: next-affordance URL between pages re-uses the original `since`/`until` even if the original request used `since=1h` shorthand (cursor encodes resolved instants)
- [ ] 7.7 [red] Functional test: tampered cursor → 400
- [ ] 7.8 [red] Functional test: cursor minted for tenant `acme` rejected when presented by tenant `widgets`
- [ ] 7.9 [red] Functional test: rotating `cursor_secret` invalidates outstanding cursors

## 8. Logs query (TDD)

- [ ] 8.1 Declare `App\Read\Resource\Log` with `#[ApiResource(routePrefix: '/v1', operations: [GetCollection(provider: LogsStateProvider::class)])]`; list the documented camelCase properties
- [ ] 8.2 [red] Unit test: each per-signal filter (`SeverityNumberFilter`, `SeverityNumberMinFilter`, `SeverityTextFilter`, `TraceIdFilter`, `SpanIdFilter`, `EventNameFilter`, `BodyContainsFilter`, `AttributeKeyFilter`) compiles a request to the documented predicate
- [ ] 8.3 [red] Test: traceId of wrong length → 400 with `traceId` in the message
- [ ] 8.4 [red] Test: two `attribute.*` filters in one request → 400 noting v1 limit
- [ ] 8.5 [green] Implement the per-signal filter classes in `App\Read\Filter\Logs\*`
- [ ] 8.6 [red] Functional test (`api-platform/core/test/ApiTestCase`): `GET /v1/logs?service=checkout&since=1h&limit=5` with valid bearer → 200, response in default Hydra format with `schemaId=logs/v1`, ≤5 entries in `hydra:member`
- [ ] 8.7 [green] Implement `App\Read\State\LogsStateProvider`
- [ ] 8.8 [red] Functional test: missing bearer → 401
- [ ] 8.9 [red] Functional test: tenant `acme` cannot see tenant `widgets` data even with valid `acme` token
- [ ] 8.10 [red] Functional test: `Accept: application/json` returns the compact envelope `{schemaId, rows, _links}`
- [ ] 8.11 [red] Functional test: `Accept: application/hal+json` returns HAL-shaped response with `_embedded.rows`
- [ ] 8.12 [red] Functional test: row with `traceIdHex` set carries an affordance `trace = /v1/traces/<hex>` (assert via format-agnostic helper)
- [ ] 8.13 [red] Functional test: row with `traceIdHex==null` does NOT carry a `trace` affordance
- [ ] 8.14 [red] Functional test: `attribute.exception.type=RuntimeException` matches by decoded JSON walk, not substring (assert by writing two log records — one with the structurally-correct attribute and one with the substring elsewhere; only the first is returned)
- [ ] 8.15 [red] Functional test: `severityNumberMin=17` filters out severity < 17
- [ ] 8.16 [red] Functional test: `traceId=<hex>&since=...&until=...` (the trace-link target) returns only that trace's logs

## 9. Traces query (TDD)

- [ ] 9.1 Declare `App\Read\Resource\Trace` with two operations (GetCollection at `/v1/traces`, Get at `/v1/traces/{traceId}`)
- [ ] 9.2 [red] Unit test: per-signal filters (`OperationNameFilter` with prefix/suffix, `KindFilter`, `StatusCodeFilter`, `HttpStatusCodeMinFilter`, `TraceIdFilter`, `ParentSpanIdFilter`, `AttributeKeyFilter`) compile to the documented predicates
- [ ] 9.3 [red] Test: `name=GET+/orders/*` parses with trailing wildcard → `ColumnLikePrefix`
- [ ] 9.4 [red] Test: `kind=BANANA` rejected with supported values listed
- [ ] 9.5 [green] Implement filters in `App\Read\Filter\Traces\*`
- [ ] 9.6 [red] Functional test: `GET /v1/traces?service=checkout&kind=SERVER&since=1h` → matching rows; per-row `trace` affordance set
- [ ] 9.7 [green] Implement `App\Read\State\TracesStateProvider` (collection)
- [ ] 9.8 [red] Unit test: `TraceTreeAssembler::assemble(rows)` groups rows back into ResourceSpans → ScopeSpans → spans, preserving order
- [ ] 9.9 [green] Implement `App\Read\Traces\TraceTreeAssembler`
- [ ] 9.10 [red] Functional test: `GET /v1/traces/<hex>` with `Accept: application/otlp+json` returns `{resourceSpans: [...], _links: {...}}`; spans grouped under their resource/scope; OTLP-shape `traceId`/`spanId` are lowercase hex
- [ ] 9.11 [red] Functional test: same path with `Accept: application/ld+json` returns AP-default Hydra normalization (does NOT carry top-level `resourceSpans`)
- [ ] 9.12 [red] Functional test: trace-by-ID `logs` affordance points to `/v1/logs?traceId=<id>&since=<start>&until=<end>`
- [ ] 9.13 [red] Functional test: trace-by-ID `metricsWithExemplars` affordance points to `/v1/metrics?exemplarTraceId=<id>&since=<start>&until=<end>`
- [ ] 9.14 [red] Functional test: missing trace ID → 404 with the searched window in the message
- [ ] 9.15 [red] Functional test: malformed trace ID (non-hex / wrong length) → 400
- [ ] 9.16 [green] Implement `App\Read\State\TraceStateProvider` (item)
- [ ] 9.17 Declare `App\Read\Resource\Span` with one operation (Get at `/v1/spans/{spanId}`)
- [ ] 9.18 [red] Functional test: `GET /v1/spans/<hex>` returns `{span:{...}, _links:{trace, logs, self}}`
- [ ] 9.19 [red] Functional test: span-by-ID `trace` affordance points to `/v1/traces/<traceIdOfThatSpan>`
- [ ] 9.20 [green] Implement `App\Read\State\SpanStateProvider`

## 10. OTLP output format normalizer (TDD)

- [ ] 10.1 [red] Component test: `App\Read\Format\OtlpTraceNormalizer::normalize($trace, 'application/otlp+json')` produces `{resourceSpans: [...], _links: {...}}` with OTLP/HTTP-JSON-shaped trace; `traceId`/`spanId` lowercase hex
- [ ] 10.2 [red] Test: normalizer is invoked only when `Accept: application/otlp+json` is requested on the Trace.Get operation
- [ ] 10.3 [red] Test: normalizer is NOT invoked on Trace GetCollection (collections never use OTLP shape)
- [ ] 10.4 [green] Implement `OtlpTraceNormalizer` and register it as a Symfony serializer normalizer with appropriate priority
- [ ] 10.5 [red] Test: format negotiation — `Accept: application/json` and `Accept: application/ld+json` continue to work for Trace.Get, returning AP-normalized shapes (no `resourceSpans` key)

## 11. Metrics query (TDD)

- [ ] 11.1 Declare `App\Read\Resource\Metric` with `GetCollection` at `/v1/metrics`
- [ ] 11.2 [red] Unit test: per-signal filters (`MetricNameFilter`, `MetricTypeFilter`, `AggregationTemporalityFilter`, `ExemplarTraceIdFilter`, `AttributeKeyFilter`) compile to documented predicates
- [ ] 11.3 [red] Test: `metricType=BANANA` rejected
- [ ] 11.4 [red] Test: wildcard in `metricName` rejected with v1-not-supported message
- [ ] 11.5 [green] Implement filters in `App\Read\Filter\Metrics\*`
- [ ] 11.6 [red] Functional test: `GET /v1/metrics?service=checkout&metricType=HISTOGRAM&since=1h` → matching rows with `metricType==HISTOGRAM`
- [ ] 11.7 [green] Implement `App\Read\State\MetricsStateProvider`
- [ ] 11.8 [red] Functional test: row with non-empty `exemplarsJson` carrying a traceId → `exemplars` affordance points to `/v1/traces/<first-exemplar-hex>`
- [ ] 11.9 [red] Functional test: row with `exemplarsJson==[]` does NOT carry an `exemplars` affordance
- [ ] 11.10 [red] Functional test: `exemplarTraceId=<hex>` matches by decoded JSON walk (not substring) — assert by writing one metric whose exemplars carry that traceId structurally and one whose exemplarsJson contains the substring elsewhere; only the first is returned

## 12. Hypermedia link rendering (shared)

- [ ] 12.1 [red] Unit test: `LinkBuilder::self(currentUrl, resolvedWindow)` returns the request URL with the resolved absolute time window (not the duration shorthand)
- [ ] 12.2 [red] Unit test: `LinkBuilder::trace(traceIdHex)`, `::span(spanIdHex)`, `::exemplars(exemplarsJson)` produce well-formed paths; `exemplars` returns null when no exemplar carries a traceId
- [ ] 12.3 [green] Implement `App\Read\Hateoas\LinkBuilder`
- [ ] 12.4 [red] Component test: format-agnostic helper `assertHasLink($response, $rel, $expectedHref)` decodes a response in any of the four formats and verifies the rel is present
- [ ] 12.5 [green] Implement the helper in `App\Tests\Support\HypermediaAssertions`
- [ ] 12.6 [red] Component test: every per-row affordance is rendered correctly into Hydra, HAL, compact JSON, and JSON:API for a fixture row that carries `traceIdHex` + `spanIdHex`

## 13. Cross-signal navigation end-to-end (TDD)

- [ ] 13.1 [red] Functional test: ingest 1 trace + matching logs + matching metrics. `GET /v1/logs?...` → follow `trace` affordance from a row → 200 with that trace tree → follow `metricsWithExemplars` → 200 with the metric rows linking back
- [ ] 13.2 [red] Functional test: a metric exemplar's `exemplars` affordance resolves to a real `/v1/traces/<hex>` that 200s
- [ ] 13.3 [red] Functional test: trace-by-ID's `logs` affordance resolves to a `/v1/logs?...` that 200s and returns logs from that trace

## 14. HTTP response conventions

- [ ] 14.1 [red] Functional test: every 2xx response carries `Cache-Control: no-store, private`
- [ ] 14.2 [red] Functional test: `Accept-Encoding: gzip` → `Content-Encoding: gzip` and the body is gzipped
- [ ] 14.3 [red] Functional test: `Accept-Encoding` absent → uncompressed response
- [ ] 14.4 [red] Functional test: GET with `Content-Length: 5` (a body) → 415 with "read endpoints take no body" message
- [ ] 14.5 [red] Functional test: `Accept: text/plain` → 415 with supported-formats list
- [ ] 14.6 [red] Functional test: search endpoint with no matches returns 200 with empty rows collection (NOT 404)
- [ ] 14.7 [red] Functional test: by-ID endpoint with unknown ID within the search window → 404

## 15. OpenAPI spec verification

- [ ] 15.1 [red] Functional test: `GET /api/docs.json` (unauthenticated) → 200 with valid OpenAPI 3.1 JSON
- [ ] 15.2 [red] Functional test: `paths` object contains `/v1/logs`, `/v1/traces`, `/v1/traces/{traceId}`, `/v1/spans/{spanId}`, `/v1/metrics`
- [ ] 15.3 [red] Functional test: `/v1/logs` GET operation lists every documented log filter under `parameters`
- [ ] 15.4 [red] Functional test: `components.securitySchemes` declares a bearer-token scheme; every read operation references it
- [ ] 15.5 [red] Functional test: spec validates against the OpenAPI 3.1 JSON schema (use `justinrainbow/json-schema` or equivalent)
- [ ] 15.6 [red] Functional test: Swagger UI at `/api/docs` returns HTML 200

## 16. Operator documentation

- [ ] 16.1 README: new "Reading data" section before the existing "Querying" (operator-side DuckDB recipes) section. Cover all five endpoints, the criteria, the `_links` model, and how to follow links from `curl + jq`. Point readers to the Swagger UI as the canonical contract
- [ ] 16.2 README: document the `crashler.read.*` config keys (window cap, page size cap, cursor secret, span lookup window, execution timeout)
- [ ] 16.3 README: re-frame the existing "Querying" (DuckDB) section as "Operator/debug recipes (not a public interface)"; clarify that runtime read compute is pure flow-php and the DuckDB recipes are an operator convenience for ad-hoc deep dives
- [ ] 16.4 README: add a "Format negotiation" subsection showing the same `GET /v1/logs?...` request with three different `Accept` headers and their three response shapes side-by-side

## 17. Spec scenario cross-check

- [ ] 17.1 Walk every `#### Scenario:` block in `specs/read-api/spec.md` and confirm a unit/component/functional test covers it
- [ ] 17.2 Walk every scenario in `specs/logs-query/spec.md`, `specs/traces-query/spec.md`, `specs/metrics-query/spec.md` and confirm coverage
- [ ] 17.3 Add tests for any unmapped scenario; capture the coverage map inline (mirrors the previous changes' audit tables)

## 18. Final validation + deploy

- [ ] 18.1 `composer test` passes with zero deprecations/notices/warnings across all three suites
- [ ] 18.2 `openspec validate add-otlp-read-api --strict` passes
- [ ] 18.3 CI green on main
- [ ] 18.4 `dep deploy stage=production` (additive, no env flag, no schema-breaking purge, no binary install). Smoke test all five endpoints + Swagger UI; confirm 200s, schemaId markers, content negotiation in three formats, and follow-the-link traversal works
- [ ] 18.5 Optional: visit `https://crashler.cedric-ziel.com/api/docs` in a browser and confirm the Swagger UI renders all five endpoints with their filters
- [ ] 18.6 Optional: post sample data, fetch it back via `/v1/logs`, follow `trace` affordance, follow `metricsWithExemplars` affordance — full cross-signal navigation against the live network
