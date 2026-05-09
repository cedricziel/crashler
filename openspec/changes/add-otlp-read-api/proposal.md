## Why

Crashler ships OTLP write for all three signals but offers no read path beyond SSH-and-DuckDB. Operators can't verify that a recent ingest landed without server access; alerting, scripts, and any future UI have nowhere to call. The on-disk schema has been treated as internal from day one, with the explicit promise that "the planned query layer will be the public read contract" — this change makes that contract real.

Rather than hand-roll a fifth thin-controller stack, we adopt **API Platform** as the read-side framework. We get content-negotiated wire formats (Hydra/JSON-LD, HAL, plain JSON, JSON:API), a filter framework that produces typed criteria without bespoke parsers, an OpenAPI 3 spec + Swagger UI auto-generated from the resource declarations, cursor pagination plumbing, and a State Provider hook that's the natural integration point for our flow-php Parquet scanner. For a "no UI today" API, the OpenAPI doc IS the consumer onboarding UX.

## What Changes

- New `GET /v1/logs`, `GET /v1/traces`, `GET /v1/metrics` collection endpoints declared as `#[ApiResource]` operations with `#[ApiFilter]`-typed criteria; per-signal `StateProvider` services translate filter context + tenant scope into a streaming `ParquetScanner` call.
- New `GET /v1/traces/{traceId}` and `GET /v1/spans/{spanId}` item operations. The trace-by-id operation returns OTLP `ResourceSpans`-shaped JSON via a **custom output format** (`application/otlp+json`) negotiated by `Accept`; the default Hydra-shaped response also works for clients that prefer the framework's normalised shape.
- All search responses are **content-negotiated**: `application/ld+json` (Hydra; default), `application/hal+json` (HAL), `application/json` (compact), and `application/vnd.api+json` (JSON:API) come from the same Resource definition. The HATEOAS payoff (cross-signal navigation between logs / traces / spans / metrics) is rendered into whichever format the client asked for — Hydra's typed `hydra:Operation`, HAL's `_links`, or compact `_links`.
- Read traffic shares the existing Bearer-token auth and tenant model — no separate read tokens, no new config. The state providers receive the authenticated `IngestUser` from Symfony Security and bound the file glob to that tenant's slug.
- Time window is a hard requirement on every search (default last 1 hour, capped at 30 days back) so a stray query never scans the entire on-disk history.
- Cursor-based pagination integrates with API Platform's pagination contract; cursors are HMAC-signed and carry the resolved criteria so following the framework-emitted `next` link doesn't depend on the client re-typing filters.
- Compute via the streaming flow-php `ParquetScanner` (same library used on the write side). Predicates compile into a tier-ordered evaluator that fails-fast on cheap top-level columns before paying decode cost on JSON-string columns; row-group statistics push down where flow-php exposes them. No external binary, no PHP extension, no install step.
- Result column names mirror the on-disk schema (`time_unix_nano`, `trace_id_hex`, `resource_service_name`, …) — emitted as **camelCase** in JSON for OTel parity (`timeUnixNano`, `traceIdHex`, `resourceServiceName`). The same casing applies to URL parameters declared by `#[ApiFilter]`s.
- Every response carries the on-disk `_schema_id` value (e.g. `"logs/v1"`) so consumers can branch on schema version exactly the way the disk markers already do.
- README documents the new read path; existing DuckDB recipes stay as operator/debug tooling, not a public interface. The OpenAPI spec at `/docs.jsonopenapi` (Swagger UI at `/docs`) is the canonical consumer contract.

Out of scope (deferred to follow-ups):

- A query DSL (LogQL/PromQL/SQL-over-HTTP). API Platform filters are typed criteria, not a DSL.
- Aggregation endpoints ("count errors per service") — separate `add-aggregation-read` change.
- Compatibility shims (Tempo / Loki / Prometheus remote-read) — separate per-target changes.
- A web UI — remains explicitly absent; the OpenAPI spec + curl + jq is the v1 consumer surface.
- Pre-aggregated rollups, materialized views, or any background indexing — none allowed (no daemon, no workers).
- Cross-signal *meta-endpoints* like `/v1/everything-for-trace/<id>` — clients compose via hypermedia links instead.
- API Platform's StateProcessor pattern (write side via AP). Writes still flow through the existing OTLP `OtlpRequestPipeline`; this change is read-only.
- `POST /v1/<signal>/search` for complex criteria. v1 stays GET-only with URL-param filters; if multi-attribute composition or array-valued criteria become real, that's the natural moment to add it.
- GraphQL endpoint. Available via API Platform but explicitly opt-in; not turned on in v1.

## Capabilities

### New Capabilities

- `read-api`: HTTP semantics shared by all read endpoints — Bearer auth, tenant scoping, time-window enforcement, cursor pagination, content-negotiated wire formats, error envelope, OpenAPI spec contract, framework integration with API Platform.
- `logs-query`: `Log` ApiResource with a GetCollection operation, filters for the documented criteria, per-row hypermedia links pointing into traces/spans.
- `traces-query`: `Trace` ApiResource with GetCollection (search) and Get (by-id, OTLP-shaped) operations, plus `Span` ApiResource with a Get (by-id) operation.
- `metrics-query`: `Metric` ApiResource with a GetCollection operation, filters for the documented criteria, per-row hypermedia links into traces via exemplars.

### Modified Capabilities

(none — `tenants`, `schema-catalog`, all `*-ingest` and `*-storage` capabilities are unchanged)

## Impact

- New code: `App\Read\Compute\ParquetScanner` (streaming flow-php scanner with tier-ordered predicate evaluation), `App\Read\Compute\PartitionPruner` (translates `[since, until]` into matching `date=…/hour=…` directory globs), `App\Read\Compute\Predicates\*` (typed predicate primitives — ColumnEquals, ColumnGreaterEqual, ColumnInRange, ColumnLikePrefix, ColumnLikeSuffix, JsonStringContains, JsonAttributeEquals), `App\Read\Cursor` (HMAC-signed cursor codec wired into AP's pagination), `App\Read\Resource\Log`/`Trace`/`Span`/`Metric` (ApiResource declarations), `App\Read\State\LogsStateProvider`/`TracesStateProvider`/`SpansStateProvider`/`MetricsStateProvider`, `App\Read\Filter\*` (custom ApiFilter implementations), `App\Read\Format\OtlpTraceNormalizer` (custom serializer for `application/otlp+json` on the Trace.Get operation).
- Auth: existing `IngestTokenAuthenticator` already handles bearer → tenant. The firewall pattern `^/v1/` already covers `GET` requests; AP's controllers are configured under that prefix.
- Config: new `crashler.read.max_time_window_days` (default 30), `crashler.read.max_page_size` (default 1000), `crashler.read.cursor_secret` (HMAC for cursor signing — sourced from `APP_SECRET`), `crashler.read.span_lookup_window_hours` (default 24), `crashler.read.execution_timeout_seconds` (default 10). New `api_platform.yaml` package config: `route_prefix: /v1`, formats list, defaults for pagination.
- Dependencies: **`api-platform/symfony` ^4** (current major). Pulls in serializer, validator, expression-language transitively. ~3–5 MB added to vendor/. flow-php is already a project dependency for writes — no new compute dependency.
- Storage: read-only access to the existing Parquet tree under `<storage-root>/<signal>/<tenant_slug>/`. No new files, no new partitions, no new layout.
- Tests: unit (predicate primitives, cursor sign/verify, tier-ordering, partition pruning, JSON attribute walk), component (real `ParquetScanner` against fixture Parquet files, OtlpTraceNormalizer round-trip, AP filter compilation, format negotiation), functional (`api-platform/core/test/ApiTestCase` for AP integration + zenstruck/browser for raw HTTP behavior — auth, tenant scope, content negotiation, error envelopes, cross-signal `_links` follow-throughs, OpenAPI spec validity).
- Production deploy: additive, no env flag, no schema-breaking purge, no binary install. `dep deploy stage=production` ships the new code; AP's request lifecycle and OpenAPI generation are part of the cache:warmup step which already runs on deploy.
