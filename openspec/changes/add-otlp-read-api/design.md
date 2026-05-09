## Context

The on-disk Parquet schema has been treated as internal from the very first ingest change ("the planned query layer will be the public read contract"). Three signals are now live in production with a shared shape: tenant-as-path-prefix, Hive partitions by ingest time, JSON-string columns for nested structures, tier-1 resource columns byte-for-byte identical across signals so cross-signal joins are a single equality predicate.

Today, "reads" mean SSH-and-DuckDB. Operators verifying a recent ingest, alerting hooks watching for error volume, scripts pulling yesterday's slow traces — none has a way in. This change opens that door without committing to a heavy framework: criteria-based REST endpoints, selective HATEOAS for navigation, and a streaming flow-php Parquet scanner doing the actual reading. No new external dependencies; the same library that writes the files reads them back.

The runtime envelope is unchanged from the write side: mod_php on shared hosting, no daemon, no background workers, one process per request. That constrains the compute model and rules out anything that needs persistent state.

## Goals / Non-Goals

**Goals:**

- `GET /v1/logs`, `GET /v1/traces`, `GET /v1/metrics` search endpoints. URL-param criteria. Tenant-scoped, time-windowed, cursor-paginated. Compact JSON.
- `GET /v1/traces/<trace_id>` returning a full OTLP `ResourceSpans` tree. `GET /v1/spans/<span_id>` for individual span lookup.
- Selective HAL `_links`: always `self` and (where applicable) `next`. Per-row links to related resources when the row carries IDs into other signals.
- Time window is mandatory and bounded (default last 1h, ≤30d back). No accidental full-history scan.
- Bearer auth + tenant scope reuse the existing write-side machinery — same `IngestTokenAuthenticator`, same `Tenant` model.
- Cursor encodes the full original criteria (HMAC-signed) so `_links.next` is sufficient for the client to fetch the next page without re-typing filters.
- Compute via streaming flow-php Parquet scanner — same library used on the write side, no external binary, no PHP extension, no install step.
- Output columns come from the on-disk schema, exposed as camelCase JSON keys. `schemaId` echoes `_schema_id` so consumers can branch on schema version.

**Non-Goals:**

- A query language. LogQL/PromQL/TraceQL-style DSLs and SQL-over-HTTP are explicitly out of scope. Endpoints with criteria are the contract.
- Aggregations. "Count errors per service" is a different shape (group-by, single-row results) and warrants its own change.
- Compatibility shims for Tempo / Loki / Prometheus remote-read. Each is a substantial spec implementation.
- Pre-aggregation, materialized views, background indexing. The runtime envelope rules these out.
- Predicate push-down at the Parquet column-statistics level. flow-php streams whole row groups and we filter in PHP afterward; the only push-down we get is partition pruning by time window. This is a deliberate trade-off (see D1).
- An embedded DuckDB or other external query engine. The runtime is pure PHP — anything that requires a binary download, an FFI extension, or root install is out.
- Cross-signal "meta-endpoints" (e.g., `GET /v1/everything-for-trace/<id>`). Clients compose via HATEOAS links — that's the entire HATEOAS payoff for v1.
- A web UI. Remains explicitly absent.

## Decisions

### D1. Compute engine: streaming flow-php Parquet scanner (only)

**Decision.** Each read request runs a streaming `App\Read\Compute\ParquetScanner` that:

1. Resolves the time window into a list of `<storage-root>/<signal>/<tenant_slug>/date=YYYY-MM-DD/hour=HH/` directories (partition pruning).
2. Iterates the matching `part-<ulid>.parquet` files sorted by ULID (creation-time order).
3. For each file, opens a flow-php `Reader` and streams row groups; for each row, evaluates the criteria as PHP predicates; emits matching rows up to `limit` and stops early.
4. Returns the result set + a position marker `(time_unix_nano, _row_id)` that becomes the cursor's payload.

No external binary. No PHP extension. flow-php is already a project dependency for writes — the same `Reader` class reads them back.

**Why.**

- Pure PHP keeps the runtime envelope intact: mod_php on All-Inkl shared hosting, no daemon, no install step beyond `composer install` (already required). One execution path → simpler tests, fewer failure modes.
- Streaming row-by-row keeps peak memory bounded: a 1000-row response over a 100k-row partition still only buffers `limit` rows.
- Partition pruning by time window is the load-bearing optimisation. Hive layout (`date=…/hour=…`) lets the pruner reduce a "last hour" query to one or two directories before any file is opened. Combined with the mandatory window cap (D2), this keeps even pathological queries to a bounded directory set.
- Early-exit on `limit` keeps best-case latency low: a query asking for 100 logs from a busy service doesn't scan the rest of the partition once it has them.

**Why this is enough (i.e., why no DuckDB).**

- The mandatory time window (D2) is the primary brake on read cost. A 24-hour window for a low-cardinality tenant is a few partition directories; even with full row-by-row evaluation in PHP, that's bounded.
- We are NOT a TSDB. We don't run `GROUP BY ... HAVING count(*) > 100 ORDER BY ...` aggregations across millions of rows — that's deferred to a separate `add-aggregation-read` change which can revisit the engine question if real workloads demand it.
- ULID-ordered file iteration with early-exit on `limit` makes "show me the most recent N records matching X" cheap regardless of partition size, which is the dominant operator workflow.
- Adding DuckDB later is additive: `ParquetScanner` can be hidden behind an interface so an alternative implementation can slot in if a future workload genuinely needs predicate push-down. Premature now.

**Alternatives considered.**

- *DuckDB shell-out.* Faster on wide queries via column-statistics push-down. Costs: external binary install on every host (All-Inkl doesn't allow root, so it's a "drop a binary into shared/bin" dance per release), per-request process spawn (~30 ms even when warm), parsing line-delimited JSON output, an entire second execution path to test. The performance gap only matters on queries we explicitly say are out of scope (aggregations, full-history scans). Skip.
- *DuckDB FFI / PHP extension.* Same fragility around PHP minor versions and root-install requirements; same value proposition (it shines on aggregations we don't support).
- *Async query API.* Requires durable state for in-flight queries and a worker to run them — the runtime envelope (no daemon, no workers) rules this out.
- *Pre-aggregation/indexes via cron.* All-Inkl has cron, so this is technically possible. But it commits us to specific query patterns; the read API is meant to be ad-hoc operator-friendly, not pre-baked.

### D2. Mandatory, bounded time window on every search

**Decision.** All search endpoints require either:

- both `since` and `until` (RFC3339 strings or unix-nano integers as strings), or
- a `since=<duration>` shorthand with implicit `until=now` (e.g. `since=1h`, `since=24h`),

and the resolved window MUST be ≤ `crashler.read.max_time_window_days` (default 30). Missing time window → 400 with a hint. Window > cap → 400 with the cap value in the message. Default if both are omitted: last 1 hour.

**Why.** Without this, `GET /v1/logs` is a full-history scan. The Hive partition layout is keyed on ingest date+hour: a bounded `[since, until]` window prunes the partition glob to just the relevant `date=…/hour=…` directories, which is what makes the read latency predictable. The 30-day cap is operator-friendly (covers the natural "last week / last month" reach) and prevents pathological queries.

**Alternatives considered.**

- *No requirement, just slow when wide.* Operators will write `GET /v1/logs?service=foo` and watch it time out. Worse: `GET /v1/logs` (no filters) would do a full read of every Parquet file ever written. Hard "no" by default.
- *Soft cap with a 206 Partial Content response.* More complex, less predictable, and the contract becomes "you might get half your answer" — bad for scripts.

### D3. Cursor-based pagination with criteria-bearing signed cursor

**Decision.** Page size cap = `crashler.read.max_page_size` (default 1000). Each search response carries `_links.next` (when more results exist) and `_links.self`. The cursor itself is an opaque string that — when decoded server-side — encodes the full original criteria (filters + time window + ordering) plus the position (last `(time_unix_nano, _row_id)` pair) and is HMAC-signed with `crashler.read.cursor_secret`.

**Why.**

- Encoding the criteria in the cursor means `_links.next` is a *complete* URL. Clients don't have to re-forward filters. This eliminates a class of bugs where a client paginates with a stale or partial filter set.
- HMAC signing prevents tampering. A client can't synthesize a cursor that bypasses the time-window cap or escapes the tenant prefix. Cursors are ephemeral; signing key rotation just invalidates outstanding cursors.
- `(time_unix_nano, _row_id)` pairs are stable under concurrent writes — new ingests append to later partition directories, so a cursor pointing at "the last row of partition X" stays valid.
- Offset pagination is unsafe under concurrent writes (a new file lands → row indices shift → results overlap or skip). Cursor avoids that.

**Alternatives considered.**

- *Time-only cursor.* Simpler, but two records can share a `time_unix_nano` (especially for batch-emitted logs) and the cursor would skip or duplicate them.
- *Stateful cursor (server-side query handle).* Requires durable state. Ruled out.

### D4. Auth + tenancy reuse the existing write-side machinery

**Decision.** Read endpoints sit under the same Symfony firewall as write (`^/v1/`). The existing `IngestTokenAuthenticator` resolves the bearer to a `Tenant`. Read scope = the tenant slug; the executor builds its Parquet glob against `<storage-root>/<signal>/<tenant->slug>/` and never reaches outside it. No new tokens, no read-only role distinction, no separate config block.

**Why.** Tenant is already a security boundary on write; the read API just preserves it. A single bearer token lets you read what you wrote — symmetric and obvious. Read-only tokens (or scoped ACL) are useful but premature: nobody's asked for them, and adding them later is a per-tenant config change, not an architectural one.

**Alternatives considered.**

- *Separate read tokens with their own firewall.* Adds a config dimension and an authentication path with no current consumer. Defer.
- *Public read with no auth.* Tenant data is private — non-starter.

### D5. Selective HAL `_links` (cross-signal navigation, not a religion)

**Decision.** Every response carries `_links.self`. Search responses with more results carry `_links.next`. Per-row `_links` are added when the row carries an ID into another signal:

- `logs` row with `traceIdHex` set → `_links.trace = /v1/traces/<hex>`
- `logs` row with `spanIdHex` set → `_links.span = /v1/spans/<hex>`
- `traces` search row → `_links.trace = /v1/traces/<hex>` (drill-into-tree)
- `spans` (inside a trace tree) → `_links.logs = /v1/logs?traceId=<hex>&spanId=<hex>&since=<trace.start>&until=<trace.end>`
- `metrics` row whose `exemplars_json` contains a `traceId` → `_links.exemplars = /v1/traces/<first-exemplar-hex>` (one trace, the most recent exemplar; multi-exemplar fan-out is left to the client)

By-ID responses (`/v1/traces/<id>`) get cross-signal `_links` at the top level: `_links.logs` (logs from that trace), `_links.metricsWithExemplars` (metrics whose exemplars reference this trace).

Aggregation responses and bulk pulls intentionally don't get per-row `_links` — bandwidth would dominate the payload. (No aggregation in v1, but the rule applies forward.)

**Why.** The schemas were *deliberately* designed for cross-signal joins (identical resource columns, exemplar trace IDs, log trace_id/span_id). Encoding that as navigable links makes follow-up queries one curl away instead of three. HMAC-signed cursors mean clients can compose `next` follows safely. And the link-rel mechanism is forward-compat: when `metrics/v2` adds new related queries, old clients ignore unknown rels.

This is **not** REST-purist HATEOAS. We don't require clients to discover the API by following links. The criteria are documented; HATEOAS layers navigability on top.

**Alternatives considered.**

- *No links — clients construct URLs from spec.* Smaller payloads, more docs friction, more "forgot to forward `since`" bugs on pagination, no forward-compat lever for adding relations.
- *JSON:API.* Heavier ("resources" model fights the analytical row-set model). The `_links` block from HAL is a 30-line convention, not a framework.
- *Always-on per-row links, including aggregations.* Bandwidth blows up on large pages. Selective is right.

### D6. Compact JSON envelope for search; OTLP `ResourceSpans` for trace-by-id

**Decision.** Search responses use a flat envelope:

```json
{
  "schemaId": "logs/v1",
  "rows": [ { "timeUnixNano": "1714…", … }, … ],
  "_links": { "self": "...", "next": "..." }
}
```

`/v1/traces/<trace_id>` returns OTLP `ResourceSpans`-shaped JSON (the same shape the OTLP spec defines for the write side), wrapped with `_links`:

```json
{
  "resourceSpans": [ … ],
  "_links": {
    "self": "/v1/traces/5b8a…",
    "logs": "/v1/logs?traceId=5b8a…&since=…&until=…",
    "metricsWithExemplars": "/v1/metrics?exemplarTraceId=5b8a…&since=…&until=…"
  }
}
```

`/v1/spans/<span_id>` returns a single span (OTLP `Span` shape) inside the same `_links` envelope.

**Why.**

- Compact envelope for search: one row per record, jq-friendly, schema-marked. A 1000-row log search fits comfortably in a few hundred KiB.
- Trace-by-id is a tree query — the OTLP shape preserves the parent/child structure without forcing the client to reconstruct it from `parent_span_id_hex`. It also matches what the OTLP SDK can deserialize directly.
- The `_links` envelope is consistent across both shapes — same convention regardless of inner format.

**Alternatives considered.**

- *Always OTLP-faithful for everything.* The wire envelope (`{resourceLogs:[{resource:{attributes:[{key,value:{stringValue:…}}]}, scopeLogs:[…]}]}`) blows up bulk responses by 50%+ overhead per row.
- *Flat tabular for trace-by-id too.* Forces the client to walk parent_span_id_hex pointers to rebuild the tree. OTLP did this work; reuse it.

### D7. camelCase JSON keys; column names match storage 1:1

**Decision.** On-disk column `time_unix_nano` → JSON key `timeUnixNano`. `trace_id_hex` → `traceIdHex`. `resource_service_name` → `resourceServiceName`. `metric_attributes_json` → `metricAttributesJson`. The transform is mechanical (snake_case → camelCase) and applied consistently. The `schemaId` field on every response identifies which schema the rows are from; the column set inside `rows` is whatever that schema declares.

**Why.** OTLP/HTTP-JSON is camelCase. The trace-by-id endpoint already returns OTLP-shaped (`timeUnixNano`, `traceId`). Mixing snake_case (logs/metrics search) with camelCase (trace-by-id) inside one API would be ugly. Storage-side snake_case is fine; it's an internal representation.

**Alternatives considered.**

- *Pass-through snake_case.* Inconsistent with the OTLP-shaped responses. Would force clients to handle both casings.
- *A "format" content-negotiation knob.* Cost without value — pick one.

### D8. Filter criteria are typed, named, and per-signal

**Decision.** Each signal endpoint accepts a fixed set of named URL params, typed and validated. Cross-signal common ones:

- `since`, `until` — RFC3339 or unix-nano numeric strings, or `since=<duration>` shorthand
- `service` (= `resource_service_name`)
- `environment` (= `resource_deployment_environment`)
- `host` (= `resource_host_name`)
- `limit`, `cursor`

Per-signal:

| Signal   | Criteria                                                                                                                          |
| -------- | --------------------------------------------------------------------------------------------------------------------------------- |
| /v1/logs | `severityNumber>=`, `severityText`, `traceId`, `spanId`, `eventName`, `bodyContains` (substring on `body_json`), `attribute.<key>` (any single attribute equality, e.g. `attribute.exception.type=…`) |
| /v1/traces | `name` (operation name, supports leading/trailing wildcard), `kind` (text: SERVER/CLIENT/...), `statusCode` (UNSET/OK/ERROR), `httpStatusCode>=`, `traceId` (alias for the by-id endpoint), `parentSpanId` (find children) |
| /v1/metrics | `metricName` (exact), `metricType` (SUM/GAUGE/...), `exemplarTraceId` (find metrics whose exemplars reference a trace) |

Anything not listed is rejected with a 400 listing the supported params for the endpoint. No free-form attribute matching beyond `attribute.<key>=<value>` equality (limit one such pair per request in v1; multi-attribute is deferred to a follow-up).

**Why.** Typed criteria translate directly to safe SQL fragments. There's no "build a where clause from arbitrary user input" — each criteria has a single, fixed compilation. Tenant escape is impossible because the tenant slug is bound at the path-glob level, not the WHERE clause. The 400-on-unknown-param policy is a forward-compat lever (clients learn quickly when they're using a typo or a deprecated name).

**Alternatives considered.**

- *Free-form `where=<json>` body.* Powerful, but it's a query language by another name. The user explicitly wanted no QL.
- *Multi-value attribute filters.* Reasonable, but requires AND/OR composition that pulls toward DSL territory. Defer.

### D9. Error shape mirrors the OTLP write-side error envelope

**Decision.** All non-200 responses return JSON with at minimum `{"message": "<human-readable>"}`. Status codes:

- 400 — bad criteria (unknown param, malformed time, window too wide)
- 401 — auth (existing)
- 404 — by-id endpoints when the trace/span doesn't exist within the tenant's tree
- 415 — content negotiation (we accept GET only — request body endpoints reject `Content-Type` other than absent or `application/json`)
- 429 — soft, optional, deferred to a future change
- 500 — scanner failure (file-system error, corrupted Parquet file, OOM)

**Why.** Symmetry with the existing `ErrorResponse::create()` shape used by the write pipeline. Operators don't relearn anything between read and write.

### D10. ParquetScanner is the only executor; no boot-time engine selection

**Decision.** `App\Read\Compute\ParquetScanner` is a regular Symfony service registered alongside `ReadPipeline`. There is no engine-choice config key, no boot-time detection, no fallback path. If a future change wants to introduce an alternative implementation (e.g., DuckDB shell-out for aggregations, or a pre-aggregated-rollup reader), it can extract a `ScansParquet` interface from `ParquetScanner` and add the alternative as a sibling service — that's a refactor of this code, not a config dimension.

**Why.** One execution path means one set of tests, one performance profile, one set of failure modes. Adding the seam now (interface + factory) without a second implementation is YAGNI; adding it later is mechanical.

**Alternatives considered.**

- *Pre-introduce the interface for future-proofing.* Tempting but premature — the right interface for "DuckDB shell-out + pre-aggregated rollups + native scan" only becomes clear once a second implementation is on the table.

## Risks / Trade-offs

- **[Risk]** Wide queries are genuinely slower than they would be on DuckDB. A query with no `service` filter over a 24-hour window means PHP evaluates filters row-by-row across every record in those partitions. → **Mitigation:** the mandatory time window (D2) is the primary brake; `limit` caps row materialisation; ULID-ordered file iteration with early-exit gets the best-case fast. Document expected latency: tenant with single-digit-MB partitions is sub-second; tenants pushing tens of MB per hour see seconds for wide windows. Aggregation queries (the workload where DuckDB really shines) are explicitly out of scope. If a workload comes along that genuinely needs push-down, that's the trigger to introduce an alternative `ScansParquet` implementation in a follow-up change.
- **[Risk]** A pathological filter (`since=30d`, no service filter, large tenant) materialises a lot of partition globs and reads many files. → **Mitigation:** time window cap; per-request execution timeout (default 10s, configurable via `crashler.read.execution_timeout_seconds`) — when exceeded, return 504 with a "narrow your filters" message. Per-tenant rate limiting is deferred.
- **[Risk]** `attribute.<key>` filter requires reading the `attributes_json` column and substring-matching in PHP. → **Mitigation:** combine with a `service` and time filter to prune partitions first; document this. Native attribute columns would fix it but require schema changes (deferred). Worth noting: this isn't a regression from "DuckDB would have done it faster" — DuckDB also has no Parquet column statistics inside a JSON-string column, so it would have scanned the same way.
- **[Risk]** Cursor secret rotation invalidates outstanding cursors mid-pagination. → **Mitigation:** acceptable — cursors are ephemeral, clients should restart from the beginning. Document this in README.
- **[Risk]** HAL `_links` make the response slightly bigger. → **Mitigation:** measured cost is ~80 bytes per row with links, ~30 bytes per response without; bulk users can request via `Accept: application/json; profile=compact-no-links` in a future change if it ever matters. Not a v1 concern.
- **[Risk]** Trace-by-id reads N partition files for a span tree. → **Mitigation:** the trace-by-id endpoint accepts an optional `since`/`until` to prune partitions; default is "search the last 24 hours" because most operator lookups are recent. Cold lookups across older windows are slower and explicitly documented.
- **[Risk]** flow-php's `Reader` may have edge cases on Parquet files written by flow-php itself but read with different settings. → **Mitigation:** the writer and reader are the same library — round-trip tests on every signal in CI catch regressions. Component tests open files written by `ParquetFileWriter` and assert row equality.
- **[Trade-off]** No aggregation in v1 means "errors per service" isn't expressible. **Cost:** scripts that want a count have to fetch records and count them. **Benefit:** ships sooner, smaller surface, aggregation gets its own design.
- **[Trade-off]** No multi-attribute filter composition. **Cost:** complex queries need post-filtering on the client. **Benefit:** stays out of DSL territory, criteria stay typed and safe.
- **[Trade-off]** Pure-PHP scanning means a busy tenant with multi-MB-per-hour ingest will see noticeable latency on wide queries. **Cost:** "show me everything from the last 7 days for service X" is seconds-to-tens-of-seconds. **Benefit:** zero deployment complexity, no binary install, no engine config dimension, one execution path. The workloads where this matters most (aggregations, full-history exports) are explicitly out of scope; the workloads it serves well (recent-records browsing, trace-by-id, "what just landed") are the primary v1 consumers.

## Migration Plan

- Additive: no migration. Existing `/v1/logs`, `/v1/traces`, `/v1/metrics` POST endpoints are untouched. The new `GET` verbs sit beside them.
- Production deploy: `dep deploy stage=production` ships the new code; the GET endpoints come up immediately. Nothing to install on the host (flow-php is already vendored).
- Rollback: redeploy the previous release tag. Read endpoints disappear; write side is unaffected.

## Open Questions

- **Should `/v1/spans/<span_id>` traverse all partitions when the span isn't found in the recent window, or 404 fast?** Current proposal: 404 fast within the configurable lookback window (default 24h); operators wanting deeper lookup pass an explicit `since` param. Re-evaluate based on early usage.
- **Multi-attribute filter composition (`attribute.foo=x&attribute.bar=y`).** Useful, but each one requires a separate JSON-string scan. Defer to a follow-up after v1 ships and we have data on real query patterns.
- **Should the cursor be opaque or self-describing?** Current proposal: opaque (HMAC-signed JSON, base64). Self-describing cursors are nicer for debugging but easier to misuse. Sticking with opaque for v1.
- **`Accept: application/x-protobuf` for read responses?** Symmetric with the write side, useful for OTel-aware clients. Defer — JSON-only in v1; revisit if a real consumer asks.
