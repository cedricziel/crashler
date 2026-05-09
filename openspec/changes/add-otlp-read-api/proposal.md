## Why

Crashler ships OTLP write for all three signals but offers no read path beyond SSH-and-DuckDB. Operators can't verify that a recent ingest landed without server access; alerting, scripts, and any future UI have nowhere to call. The on-disk schema has been treated as internal from day one, with the explicit promise that "the planned query layer will be the public read contract" — this change makes that contract real.

## What Changes

- New `GET /v1/logs`, `GET /v1/traces`, `GET /v1/metrics` search endpoints that take URL-param criteria (no query language) and return tenant-scoped, time-windowed results in a compact JSON shape.
- New `GET /v1/traces/<trace_id>` and `GET /v1/spans/<span_id>` by-ID lookups that return OTLP `ResourceSpans`-shaped responses (preserving the natural span tree).
- Read traffic shares the existing Bearer-token auth and tenant model — no separate read tokens, no new config. Read scope = same tenant slug as write scope.
- All responses carry **selective HAL-style `_links`**: always `self` and (where applicable) `next`; per-row links to related resources where the row carries an ID into another signal (`logs.row._links.trace` when `trace_id_hex` is set, etc.). Aggregations and dense bulk pulls deliberately don't get per-row links.
- Time window is a hard requirement on every search (default last 1 hour, capped at 30 days back) so a stray query never scans the entire on-disk history.
- Cursor-based pagination with the cursor encoding the original criteria — the `_links.next` URL is the cursor; clients just follow it.
- Compute via a streaming **flow-php native Parquet scanner** — the same library already in use for the write side. No external binary, no PHP extension, no install step. The scanner reads matching files row-by-row and applies filters in PHP after partition pruning has narrowed the input down to the relevant `date=…/hour=…` directories.
- Result columns mirror the on-disk schema names (`time_unix_nano`, `trace_id_hex`, `resource_service_name`, …) — emitted as **camelCase** in JSON for OTel parity (`timeUnixNano`, `traceIdHex`, `resourceServiceName`).
- Response carries a `schemaId` field (e.g. `"logs/v1"`) so clients can branch on schema version exactly the way `_schema_id` already does on disk.
- README documents the read API; the existing DuckDB recipes stay as operator/debug tooling, not a public interface.

Out of scope (deferred to follow-ups):

- A query language (LogQL/PromQL/SQL-over-HTTP) — endpoints with criteria are the v1 contract.
- Aggregation endpoints ("count errors per service") — separate `add-aggregation-read` change.
- Compatibility shims (Tempo / Loki / Prometheus remote-read) — separate per-target changes.
- A web UI — remains explicitly absent; this API is its own primary consumer surface.
- Pre-aggregated rollups, materialized views, or any background indexing — none allowed (no daemon, no workers).
- Cross-signal *meta-endpoints* like `/v1/everything-for-trace/<id>` — clients compose via HATEOAS links instead.

## Capabilities

### New Capabilities

- `read-api`: HTTP semantics shared by all read endpoints — Bearer auth, tenant scoping, time-window enforcement, cursor pagination, compact JSON envelope, HAL-style `_links`, schema-version markers, OTLP-style error shape, content negotiation.
- `logs-query`: `GET /v1/logs` search and per-row `_links` for trace/span navigation.
- `traces-query`: `GET /v1/traces` search, `GET /v1/traces/<trace_id>` (full tree), `GET /v1/spans/<span_id>` (single span). Returns OTLP `ResourceSpans` for tree responses.
- `metrics-query`: `GET /v1/metrics` search, including `metricName` and `metricType` criteria; per-row `_links` to exemplar traces.

### Modified Capabilities

(none — `tenants`, `schema-catalog`, all `*-ingest` and `*-storage` capabilities are unchanged)

## Impact

- New code: `App\Read\ReadPipeline` (request → criteria → tenant-scoped scan → JSON envelope), `App\Read\Compute\ParquetScanner` (streaming flow-php scanner with predicate evaluation), `App\Read\Compute\PartitionPruner` (translates `[since, until]` into the matching `date=…/hour=…` directory globs), `App\Read\Criteria\*` (decoders per signal), `App\Read\Cursor` (signed cursor encoding), `App\Read\Hateoas\LinkBuilder`, `App\Controller\ReadLogsController`, `App\Controller\ReadTracesController`, `App\Controller\ReadMetricsController`.
- Auth: existing `IngestTokenAuthenticator` already handles bearer → tenant. The firewall pattern `^/v1/` already covers `GET` requests.
- Config: new `crashler.read.max_time_window_days` (default 30), new `crashler.read.max_page_size` (default 1000), new `crashler.read.cursor_secret` (HMAC for cursor signing — sourced from `APP_SECRET`), new `crashler.read.span_lookup_window_hours` (default 24).
- Dependencies: none — flow-php is already a project dependency.
- Storage: read-only access to the existing Parquet tree under `<storage-root>/<signal>/<tenant_slug>/`. No new files, no new partitions, no new layout.
- Tests: unit (criteria parsers, cursor sign/verify, HATEOAS link generation, scanner behavior on synthetic fixtures), component (real `ParquetScanner` against fixture Parquet files, cross-signal `_links` generation), functional (zenstruck/browser auth + tenant scoping + happy paths + error paths).
- Production deploy: additive, no env flag, no schema-breaking purge, no binary to install. `dep deploy stage=production` ships the new code and the new endpoints come up immediately.
