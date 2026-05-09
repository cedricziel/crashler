## Context

The on-disk Parquet schema has been treated as internal from the very first ingest change ("the planned query layer will be the public read contract"). Three signals are now live in production with a shared shape: tenant-as-path-prefix, Hive partitions by ingest time, JSON-string columns for nested structures, tier-1 resource columns byte-for-byte identical across signals so cross-signal joins are a single equality predicate.

Today, "reads" mean SSH-and-DuckDB. Operators verifying a recent ingest, alerting hooks watching for error volume, scripts pulling yesterday's slow traces — none has a way in. This change opens that door.

Two design choices shape everything that follows:

1. **API Platform is the framework.** It absorbs hand-rolled scaffolding (routing, content negotiation, filter parsing, OpenAPI generation, hypermedia rendering, pagination plumbing) and gives us multiple wire formats from one Resource declaration. For a no-UI-today read API, the OpenAPI spec + Swagger UI is the consumer onboarding UX; that alone justifies the framework's weight.

2. **Compute is the streaming flow-php Parquet scanner.** Same library that writes the files reads them back. No external binary, no PHP extension, no install step on the All-Inkl host. Predicate push-down is limited to time-window partition pruning and Parquet row-group statistics; richer push-down would require a query engine we explicitly don't want to depend on.

The runtime envelope is unchanged from the write side: mod_php on shared hosting, no daemon, no background workers, one process per request. That constrains the compute model and rules out anything that needs persistent state.

## Goals / Non-Goals

**Goals:**

- `GET /v1/logs`, `GET /v1/traces`, `GET /v1/metrics` collection endpoints declared as API Platform resources, with `#[ApiFilter]`-typed criteria. Tenant-scoped, time-windowed, cursor-paginated.
- `GET /v1/traces/{traceId}` and `GET /v1/spans/{spanId}` item endpoints. Trace-by-id returns OTLP `ResourceSpans`-shaped JSON via a custom output format negotiated by `Accept: application/otlp+json`; the default Hydra response is also available.
- Content-negotiated wire formats: Hydra (default, `application/ld+json`), HAL (`application/hal+json`), compact JSON (`application/json`), JSON:API (`application/vnd.api+json`), and OTLP (`application/otlp+json`, only on the trace-by-id operation). Same data, different projections, one Resource declaration.
- Cross-signal hypermedia links wherever a row carries an ID into another signal: log→trace, log→span, trace search→trace tree, trace tree→logs, trace tree→metrics-with-exemplars, metric→trace via exemplar.
- Time window is mandatory and bounded (default last 1h, ≤30d back). No accidental full-history scan.
- Bearer auth + tenant scope reuse the existing write-side machinery — same `IngestTokenAuthenticator`, same `Tenant` model. The state providers see the authenticated `IngestUser` and bound their file glob to its slug.
- Cursor encodes the full original criteria (HMAC-signed) and integrates with API Platform's pagination contract so the framework's `next` link is sufficient for the client.
- Compute via streaming `ParquetScanner`. Predicates compile into a tier-ordered evaluator (cheap top-level columns first, expensive JSON-string scans last) so wide queries fail-fast on the cheap predicates.
- OpenAPI 3 spec auto-generated at `/docs.jsonopenapi`, Swagger UI at `/docs`. This is the canonical consumer contract.

**Non-Goals:**

- A query language. API Platform's filter framework produces *typed criteria*, not a DSL. LogQL/PromQL/TraceQL/SQL-over-HTTP are out.
- Aggregations. "Count errors per service" is a different shape (group-by, single-row results) and warrants its own change. The "no aggregations" decision is now load-bearing: it's the workload that would push us toward a real query engine.
- Compatibility shims for Tempo / Loki / Prometheus remote-read. Each is a substantial spec implementation; out of scope.
- Pre-aggregation, materialized views, background indexing. The runtime envelope rules these out.
- Predicate push-down at the Parquet column-statistics level beyond what flow-php exposes (row-group min/max). Anything richer needs DuckDB or an FFI extension.
- A web UI. Remains explicitly absent.
- API Platform's StateProcessor pattern (write side via AP). Writes still flow through the existing `OtlpRequestPipeline`.
- GraphQL endpoint. Available via API Platform but explicitly opt-in; not turned on in v1.
- `POST /v1/<signal>/search` for complex criteria. v1 stays GET-only; if multi-attribute filter composition or array-valued criteria become real, that's the natural moment to add it.
- Cross-signal "meta-endpoints" (e.g., `GET /v1/everything-for-trace/<id>`). Clients compose via hypermedia links — that's the entire HATEOAS payoff.

## Decisions

### D1. Compute engine: streaming flow-php Parquet scanner (only)

**Decision.** Each read request runs a streaming `App\Read\Compute\ParquetScanner` that:

1. Resolves the time window into a list of `<storage-root>/<signal>/<tenant_slug>/date=YYYY-MM-DD/hour=HH/` directories (partition pruning).
2. Iterates the matching `part-<ulid>.parquet` files sorted by ULID (creation-time order).
3. For each file, opens a flow-php `Reader`. Where flow-php exposes per-row-group min/max statistics, the scanner skips groups whose range disjoints from the predicate (Tier 1 push-down — see D13). For each surviving row, evaluates the criteria as PHP predicates in tier order; emits matching rows up to `limit` and stops early.
4. Returns the result set + a position marker `(time_unix_nano, row_id)` that becomes the cursor's payload.

No external binary. No PHP extension. flow-php is already a project dependency for writes — the same `Reader` class reads them back.

**Why.**

- Pure PHP keeps the runtime envelope intact: mod_php on All-Inkl shared hosting, no daemon, no install step beyond `composer install` (already required). One execution path → simpler tests, fewer failure modes.
- Streaming row-by-row keeps peak memory bounded: a 1000-row response over a 100k-row partition still only buffers `limit` rows.
- Partition pruning by time window is the load-bearing optimisation. Hive layout (`date=…/hour=…`) lets the pruner reduce a "last hour" query to one or two directories before any file is opened. Combined with the mandatory window cap (D2), this keeps even pathological queries to a bounded directory set.
- Row-group statistics push-down (Tier 1, D13) is real even in pure PHP — flow-php exposes the metadata. A `severityNumberMin=17` filter skips row groups whose `max(severity_number) < 17` without reading any data.
- Early-exit on `limit` keeps best-case latency low: a query asking for 100 logs from a busy service doesn't scan the rest of the partition once it has them.

**Why this is enough.** The mandatory time window (D2) is the primary brake on read cost. Aggregation queries (the workload where DuckDB really shines) are explicitly out of scope. ULID-ordered file iteration with early-exit on `limit` makes "show me the most recent N records matching X" cheap regardless of partition size, which is the dominant operator workflow. Adding a richer engine later is additive: `ParquetScanner` can be hidden behind a `ScansParquet` interface so an alternative implementation slots in if a future workload genuinely needs richer push-down. Premature now.

**Alternatives considered.**

- *DuckDB shell-out.* Faster on wide queries via column-statistics push-down. Costs: external binary install on every host (All-Inkl doesn't allow root, so it's a "drop a binary into shared/bin" dance per release), per-request process spawn (~30 ms even when warm), parsing line-delimited JSON output, an entire second execution path to test. The performance gap only matters on queries we explicitly say are out of scope (aggregations, full-history scans). Skip.
- *DuckDB FFI / PHP extension.* Same fragility around PHP minor versions and root-install requirements; same value proposition (it shines on aggregations we don't support).
- *Async query API.* Requires durable state for in-flight queries and a worker to run them — the runtime envelope (no daemon, no workers) rules this out.
- *Pre-aggregation/indexes via cron.* Technically possible (All-Inkl has cron). But it commits us to specific query patterns; the read API is meant to be ad-hoc operator-friendly, not pre-baked.

### D2. Mandatory, bounded time window on every search

**Decision.** All search endpoints require either:

- both `since` and `until` (RFC3339 strings or unix-nano integers as strings), or
- a `since=<duration>` shorthand with implicit `until=now` (e.g. `since=1h`, `since=24h`),

and the resolved window MUST be ≤ `crashler.read.max_time_window_days` (default 30). Missing time window → window defaults to "last 1 hour". Window > cap → 400 with the cap value in the message. Mixing absolute `until` with shorthand `since=<duration>` → 400 (mixed semantics).

**Why.** Without this, `GET /v1/logs` is a full-history scan. The Hive partition layout is keyed on ingest date+hour: a bounded `[since, until]` window prunes the partition glob to just the relevant `date=…/hour=…` directories, which is what makes the read latency predictable. The 30-day cap is operator-friendly (covers the natural "last week / last month" reach) and prevents pathological queries.

**Alternatives considered.**

- *No requirement, just slow when wide.* Operators will write `GET /v1/logs?service=foo` and watch it time out. Worse: `GET /v1/logs` (no filters) would do a full read of every Parquet file ever written. Hard "no" by default.
- *Soft cap with a 206 Partial Content response.* More complex, less predictable, and the contract becomes "you might get half your answer" — bad for scripts.

### D3. Cursor-based pagination, HMAC-signed, integrated with API Platform

**Decision.** Page size cap = `crashler.read.max_page_size` (default 1000). Cursors are opaque base64url strings whose decoded payload carries:

- the resolved criteria (filters + absolute `since`/`until` instants + ordering + limit),
- the position marker `(last_time_unix_nano, last_row_id)`,
- the tenant slug (for cross-tenant defense),
- HMAC-SHA256 signature with `crashler.read.cursor_secret`.

API Platform 4's cursor pagination contract is implemented by a custom `App\Read\Cursor\CursorPaginator` that wraps the scanner's iterator and emits the next-page hypermedia affordance in whichever format the request negotiated (`hydra:next` for Hydra, `_links.next` for HAL/compact JSON, etc.).

**Why.**

- Encoding the criteria in the cursor means the framework-emitted next link is a *complete* request the client just follows. Clients don't have to re-forward filters. This eliminates a class of bugs where a client paginates with a stale or partial filter set.
- HMAC signing prevents tampering. A client can't synthesize a cursor that bypasses the time-window cap or escapes the tenant prefix. Cursors are ephemeral; signing-key rotation just invalidates outstanding cursors.
- `(time_unix_nano, row_id)` pairs are stable under concurrent writes — new ingests append to later partition directories, so a cursor pointing at "the last row of partition X" stays valid.
- Offset pagination is unsafe under concurrent writes (a new file lands → row indices shift → results overlap or skip). Cursor avoids that.
- AP's `paginationViaCursor` plumbing handles the wire-shape rendering per format; we only own the cursor codec and the position-resume logic in the state provider.

**Alternatives considered.**

- *AP's default offset/page pagination.* Race conditions under concurrent writes; unsafe.
- *Self-describing cursor (JSON with HMAC trailer).* Easier to debug; easier to forge if the HMAC check is ever skipped. Opaque is conservative.
- *Stateful cursor (server-side query handle).* Requires durable state. Ruled out.

### D4. Auth + tenancy reuse the existing write-side machinery

**Decision.** Read endpoints sit under the same Symfony firewall as write (`^/v1/`). The existing `IngestTokenAuthenticator` resolves the bearer to a `Tenant` and exposes an `IngestUser` to Symfony Security. State providers receive the user via `Symfony\Bundle\SecurityBundle\Security` and build the Parquet glob against `<storage-root>/<signal>/<tenant->slug>/` exclusively. No new tokens, no read-only role distinction, no separate config block.

**Why.** Tenant is already a security boundary on write; the read API just preserves it. A single bearer token lets you read what you wrote — symmetric and obvious. Read-only tokens (or scoped ACL) are useful but premature: nobody's asked for them, and adding them later is a per-tenant config change, not an architectural one.

**Alternatives considered.**

- *Separate read tokens with their own firewall.* Adds a config dimension and an authentication path with no current consumer. Defer.
- *Public read with no auth.* Tenant data is private — non-starter.

### D5. API Platform 4 as the framework

**Decision.** Adopt `api-platform/symfony` ^4 for routing, content negotiation, filter parsing, OpenAPI generation, hypermedia rendering, and pagination wiring. Each signal is declared as a PHP-attribute `#[ApiResource]` on a plain DTO class (Log, Trace, Span, Metric). State providers (`StateProviderInterface` implementations) are the only logic layer we own — they translate Operation + filters + tenant into a `ParquetScanner` call.

API Platform is configured under `routePrefix: /v1` so endpoints land at `/v1/logs`, `/v1/traces`, `/v1/traces/{traceId}`, `/v1/spans/{spanId}`, `/v1/metrics`. Documentation is exposed at `/docs.jsonopenapi` (OpenAPI 3) and `/docs` (Swagger UI).

**Why.**

- Five Resources × four output formats × auto-generated OpenAPI is more than a thin-controller stack absorbs at low cost. AP earns its keep on the *combined* surface — OpenAPI alone is worth the dependency for a no-UI read API.
- StateProvider is a clean integration point for non-Doctrine sources. We get filter parsing, validation, content negotiation, hypermedia rendering, and OpenAPI doc for free; we provide rows.
- Format profusion is genuinely useful: operators with curl/jq want compact JSON, future LLM agents and UIs want Hydra's typed discoverability, integration partners may want HAL or JSON:API. One Resource declaration produces all of them.
- The Symfony ecosystem keeps AP maintained, which matters for the long tail.

**Why not.**

- Heavier dependency than hand-rolled. ~3–5 MB to vendor/. Justified once the OpenAPI/format/filter value lands; not justified for a single endpoint.
- AP's "Resource" model is built around CRUD entities with stable URIs. Crashler's analytical data has no per-record stable URI. We use AP's collection operations and item operations selectively (Trace and Span have item ops, Log and Metric only have collection ops — see D7). This is a supported pattern, not a hack, but it leaves some AP machinery (resource-IRI generation, JSON-LD context for items) under-used.
- OTLP-faithful trace-by-id needs a custom output format. Doable cleanly via AP's normalizer hook (D8).
- Locks us into AP's request lifecycle + serializer. Performance is acceptable: AP overhead is ~5–20 ms per request; scan time dominates.

**Alternatives considered.**

- *Hand-rolled controllers + nelmio/api-doc-bundle for OpenAPI.* Lighter dependency footprint; loses the filter framework, the format negotiation, and the hypermedia rendering. We'd reinvent each as a half-baked version.
- *Plain Symfony with a custom serializer per format.* Maximum control, maximum hand-rolled code, no OpenAPI without writing it by hand. Not worth the effort for this surface size.
- *GraphQL-only via api-platform/graphql.* Single endpoint, typed schema, great for UIs/agents — but worse for curl-and-jq operators. Hybrid approaches end up with two contracts to maintain. Skip.

### D6. Content-negotiated wire formats

**Decision.** Search responses are content-negotiated. The default is Hydra (`application/ld+json`); clients can request HAL (`application/hal+json`), compact JSON (`application/json`), or JSON:API (`application/vnd.api+json`). The Trace.Get item operation adds a fifth format, OTLP (`application/otlp+json`), specifically for the OTLP `ResourceSpans`-shaped tree response (see D8).

The hypermedia links described under D9 (cross-signal navigation) are rendered into whichever format the client requested:

- Hydra: `hydra:next`, `hydra:view`, `hydra:Operation` per relation.
- HAL: `_links.next`, `_links.trace`, `_links.span`, etc.
- Compact JSON: a top-level `_links` block with the same keys as HAL but without the wrapping `{href: ...}` objects.
- JSON:API: `links.next`, `relationships.<rel>.links.related`.

**Why.** Different consumers want different shapes; AP gives us all of them from one Resource declaration. Curl-and-jq operators ask for compact JSON; future LLM agents or UIs ask for Hydra; integration partners may want HAL. We don't pay anything to support all four.

**Why not.** Format profusion is real test surface: every cross-signal link assertion has to either be format-agnostic (decode the response and look for the link by path) or repeat per format. We mitigate by writing format-agnostic helpers in tests (`assertHasLink($response, $rel, $href)`).

### D7. Resource topology — Item vs Collection per signal

**Decision.** Resources declare only the operations that semantically apply:

| Resource | GetCollection (`/v1/<plural>`)         | Get (`/v1/<plural>/{id}`)                       |
| -------- | -------------------------------------- | ----------------------------------------------- |
| `Log`    | yes (search)                           | no (log records have no stable per-row URI)     |
| `Trace`  | yes (search by criteria)               | yes (trace tree by trace_id)                    |
| `Span`   | no (reach via Trace tree or Log links) | yes (span by span_id)                           |
| `Metric` | yes (search)                           | no (data-points have no stable per-row URI)     |

The path conventions are:

```
GET /v1/logs                       (Log GetCollection)
GET /v1/traces                     (Trace GetCollection)
GET /v1/traces/{traceId}           (Trace Get)
GET /v1/spans/{spanId}             (Span Get)
GET /v1/metrics                    (Metric GetCollection)
```

**Why.** AP is happy with resources that have only some operations. Logs and metric data-points are events, not entities — they have no canonical address. Forcing a synthetic ID (`<file_part>:<row_index>`) would invent contracts nobody asked for and complicate the wire shape. Spans, on the other hand, *do* have a canonical address (their span_id), and so do traces (trace_id). Item operations are added precisely where they're meaningful.

**Why not (alternatives considered).**

- *Make Log and Metric items addressable by `<file_part>:<row_index>`.* Synthetic, fragile under file compaction, no consumer demand. Skip.
- *Skip the Span Get operation.* Possible — clients reach spans via the Trace tree. But span-by-id is a frequent debug entry point ("I have a span_id from a log line and want the span") and adding it is cheap.

### D8. OTLP-faithful trace-by-id via custom output format

**Decision.** The Trace.Get operation registers `application/otlp+json` as a format with a custom `App\Read\Format\OtlpTraceNormalizer`. When a client sets `Accept: application/otlp+json`, the normalizer emits the OTLP `ResourceSpans`-shaped JSON wrapped in the appropriate hypermedia envelope:

```json
{
  "resourceSpans": [...],   // OTLP/HTTP-JSON shape, exactly as written
  "_links": {
    "self": "/v1/traces/<id>",
    "logs": "/v1/logs?traceId=<id>&since=...&until=...",
    "metricsWithExemplars": "/v1/metrics?exemplarTraceId=<id>&since=...&until=..."
  }
}
```

Other Accept headers continue to work — a client that asks for `application/ld+json` gets a Hydra-normalized representation of the Trace resource, which is fine for consumers that don't need the OTLP tree shape. Search collection endpoints (`/v1/traces`) do NOT return OTLP shape (they return per-span rows, not trees).

**Why.** The OTLP `ResourceSpans` shape preserves the parent/child structure without forcing the client to walk `parent_span_id_hex` pointers to rebuild the tree. It also matches what the OTel SDK can deserialize directly. AP's normalizer hook supports custom formats cleanly — one normalizer class, one format registration, no breaking the framework's conventions.

The inner `traceId`/`spanId` fields in the OTLP response are emitted as **lowercase hex** per the OTLP/HTTP-JSON spec's special case for those byte fields (the proto3-JSON spec emits other byte fields as base64; `traceId`/`spanId` are explicitly carved out as hex). The on-disk values are raw bytes; the normalizer converts.

**Alternatives considered.**

- *Custom controller per operation, bypassing AP's serializer.* Loses content negotiation for that endpoint; fragments the docs.
- *Don't use AP for Trace.Get / Span.Get at all.* Plain Symfony controllers for those two paths; AP for the rest. Two-frameworks-in-one-project, OpenAPI doc no longer covers everything. Skip.
- *Always-OTLP for Trace.Get regardless of Accept.* Loses negotiation; clients that want a normalized resource for some reason can't get it.

### D9. Hypermedia: selective cross-signal navigation, format-rendered

**Decision.** Every response carries (in the requested format's hypermedia idiom) a `self` and (for paginated collections with more results) a `next` affordance. Per-row links are added when a row carries an ID into another signal:

- `Log` row with non-null `traceIdHex` → `trace` link to `/v1/traces/<hex>`
- `Log` row with non-null `spanIdHex` → `span` link to `/v1/spans/<hex>`
- `Trace` search row → `trace` link to `/v1/traces/<hex>` (drill-into-tree)
- `Metric` row whose `exemplarsJson` carries at least one exemplar with a `traceId` → `exemplars` link to `/v1/traces/<first-exemplar-hex>` (one trace, the first exemplar; multi-exemplar fan-out is left to the client by reading `exemplarsJson` directly)

By-ID responses get cross-signal links at the top level: Trace.Get carries `logs` and `metricsWithExemplars` URLs scoped to the trace's time bounds; Span.Get carries `trace` and `logs` URLs.

Aggregation responses (when they ship in a follow-up change) and dense bulk pulls deliberately don't get per-row links — bandwidth would dominate the payload.

**Why.** The schemas were *deliberately* designed for cross-signal joins. Encoding that as navigable links makes follow-up queries one curl away instead of three. AP renders the link affordances per format so we don't write Hydra/HAL/JSON:API serialization three times.

**Why not (alternatives considered).**

- *No links — clients construct URLs from spec.* More docs friction, more "forgot to forward `since`" bugs on pagination, no forward-compat lever for adding relations.
- *Always-on per-row links, including aggregations.* Bandwidth blows up on large pages. Selective is right.
- *HAL-only (drop other formats).* Cheaper in test surface but loses the AP-default Hydra typed discoverability.

### D10. camelCase JSON keys + URL parameters; column names match storage 1:1

**Decision.** On-disk column `time_unix_nano` → JSON key and URL param key `timeUnixNano`. `trace_id_hex` → `traceIdHex`. `resource_service_name` → `resourceServiceName`. The Resource DTO property names are camelCase to match. Filters declared on the Resource use the same camelCase names. The transform is mechanical (snake_case → camelCase) and applied consistently.

**Why.** OTLP/HTTP-JSON is camelCase. The Trace.Get OTLP-faithful response uses camelCase. URL params on the search endpoints use camelCase too — same convention everywhere. Storage-side snake_case is fine; it's an internal representation that the state provider rewrites to the Resource shape.

**Alternatives considered.**

- *Pass-through snake_case.* Inconsistent with the OTLP-shaped responses. Would force clients to handle both casings.
- *kebab-case URL params.* More common in some REST styles, but inconsistent with the JSON keys. Pick one — camelCase wins because of OTLP parity.

### D11. Typed criteria via `#[ApiFilter]` (no DSL)

**Decision.** Each search endpoint accepts a documented set of named query parameters declared via `#[ApiFilter]` attributes on the Resource class. AP parses and validates them; the filter classes feed typed values into the state provider's context. Custom filter classes implement `App\Read\Filter\<Name>Filter` for the per-signal criteria; AP's built-in `SearchFilter` covers the simple equality cases on top-level columns when paired with the appropriate state-provider integration.

Common filter set (all signals): `service`, `environment`, `host`, `since`, `until`, `limit`, `cursor`. Per-signal filter sets are declared in the per-signal specs. Unknown query parameters are rejected by AP with a 400 listing the supported ones.

The full enumeration of supported criteria per signal is:

| Signal | Filter | Predicate |
| ------ | ------ | --------- |
| logs | `severityNumber` (int eq) | `ColumnEquals('severity_number', v)` |
| logs | `severityNumberMin` (int) | `ColumnGreaterEqual('severity_number', v)` |
| logs | `severityText` (string) | `ColumnEquals('severity_text', v)` |
| logs | `traceId` (hex32) | `ColumnEquals('trace_id_hex', v)` |
| logs | `spanId` (hex16) | `ColumnEquals('span_id_hex', v)` |
| logs | `eventName` (string) | `ColumnEquals('event_name', v)` |
| logs | `bodyContains` (string) | `JsonStringContains('body_json', v)` |
| logs | `attribute.<key>` (string) | `JsonAttributeEquals('attributes_json', key, v)` |
| traces | `name` (exact OR prefix `X*` OR suffix `*X`) | `ColumnEquals` / `ColumnLikePrefix` / `ColumnLikeSuffix` |
| traces | `kind` (enum) | `ColumnEquals('kind_text', v)` |
| traces | `statusCode` (enum) | `ColumnEquals('status_text', v)` |
| traces | `httpStatusCodeMin` (int) | `ColumnGreaterEqual('http_response_status_code', v)` |
| traces | `traceId` (hex32) | `ColumnEquals('trace_id_hex', v)` |
| traces | `parentSpanId` (hex16) | `ColumnEquals('parent_span_id_hex', v)` |
| traces | `attribute.<key>` (string) | `JsonAttributeEquals('attributes_json', key, v)` |
| metrics | `metricName` (exact only) | `ColumnEquals('metric_name', v)` |
| metrics | `metricType` (enum) | `ColumnEquals('metric_type', v)` |
| metrics | `aggregationTemporality` (enum) | `ColumnEquals('aggregation_temporality_text', v)` |
| metrics | `exemplarTraceId` (hex32) | `JsonAttributeEquals('exemplars_json', 'traceId', v)` |
| metrics | `attribute.<key>` (string) | `JsonAttributeEquals('attributes_json', key, v)` |

`attribute.<key>` filters are limited to one per request in v1 (multi-attribute composition is deferred). `JsonAttributeEquals` is a *decoded JSON walk*, not a substring match — it's defended against false positives where a value happens to contain the key spelling.

**Why.** Typed criteria translate directly to safe predicates. There's no "build a filter from arbitrary user input" risk — each criterion has a single, fixed compilation. Tenant escape is impossible because the tenant slug is bound at the path-glob level, not in the predicate. The `#[ApiFilter]` attributes also surface in the OpenAPI spec automatically (D14).

**Alternatives considered.**

- *Free-form `where=<json>` body.* Powerful, but it's a query language by another name. Rejected per the user's "less QL" preference.
- *Multi-value attribute filters.* Reasonable, but requires AND/OR composition that pulls toward DSL territory. Defer.
- *Substring match on JSON-string columns.* False-positive prone for `attribute.<k>` (a value might contain the key spelling) and `exemplarTraceId` (a 32-char hex might appear in another JSON field). Decoded walk is the right semantic; the per-row decode cost is acceptable when partition pruning + tier-ordered evaluation already shrink the row set.

### D12. Predicate model + tier-ordered evaluation

**Decision.** Filters compile into typed predicate primitives:

- `ColumnEquals(col, val)` — string/int equality on a top-level column.
- `ColumnGreaterEqual(col, val)` — int comparison, top-level column.
- `ColumnInRange(col, low, high)` — int range (used by the time window).
- `ColumnLikePrefix(col, prefix)` — `starts_with` on a top-level string column.
- `ColumnLikeSuffix(col, suffix)` — `ends_with` on a top-level string column.
- `JsonStringContains(col, needle)` — `strpos` on a JSON-string column (used for `bodyContains`).
- `JsonAttributeEquals(col, key, value)` — decode the JSON column, walk the attribute array, match `{key, value.stringValue}` (defends against substring false positives).

Predicates are evaluated in tier order, cheap-first:

```
TIER 0  Partition pruning            (ColumnInRange on time_unix_nano)
TIER 1  Row-group statistics         (numeric eq/range using flow-php's
                                      per-row-group min/max metadata)
TIER 2  Top-level column predicates  (ColumnEquals / ColumnGreaterEqual /
                                      ColumnLikePrefix / ColumnLikeSuffix)
TIER 3  JSON-string column scans     (JsonStringContains, JsonAttributeEquals)
```

Inside Tier 2 and Tier 3, the order is also cheap-first: `ColumnEquals` before `ColumnLikePrefix`, top-level columns before JSON decodes. Short-circuit on first failing predicate skips the row before any expensive predicate runs.

**Why.** The cost ratio between Tier 2 and Tier 3 is roughly 100×: a `strcmp` is tens of nanoseconds; a `json_decode` of an attributes array is single-digit microseconds. A row that fails the cheap `service=checkout` filter never pays the JSON decode cost for an attribute filter. On wide queries with selective filters, this is the difference between sub-second and many-second latency.

**Note on predicate "pushdown" via flow-php row groups.** flow-php's `Reader` exposes per-row-group statistics (min, max, null count) for typed columns. The scanner reads these before opening the row group's data and skips groups whose `[min, max]` disjoints from the predicate. This works for numeric columns and for short string columns where Parquet stores statistics; it does NOT work for JSON-string columns (no useful column statistics inside a string blob). Tier 1 is therefore meaningful for `severityNumberMin`, `httpStatusCodeMin`, and the time-window range, and a no-op for `bodyContains` / `attribute.<k>` / `exemplarTraceId`.

**Alternatives considered.**

- *Random predicate order.* Trivial to implement, factor-of-100× worse on the hot path. No.
- *Cost-based predicate planner that learns from past runs.* Too clever for the surface size; defer indefinitely.

### D13. HTTP request/response conventions

**Decision.** Read endpoints follow these conventions:

- **Casing.** URL parameter names and JSON response keys are camelCase. URL path segments are lowercase (`/v1/logs`, `/v1/traces/{traceId}`).
- **GET only in v1.** The endpoints take no request body. A GET with `Content-Length > 0` → 415 ("read endpoints take no body").
- **Accept header.** `application/ld+json` (default), `application/hal+json`, `application/json`, `application/vnd.api+json`, `application/otlp+json` (Trace.Get only). Unsupported `Accept` → 415.
- **Accept-Encoding.** Responses are gzipped if the client sends `Accept-Encoding: gzip`. Symmetric with the write side accepting gzip; ~5 lines in the response handler. Free win for big rows-arrays.
- **Repeated query parameters.** `?service=foo&service=bar` → 400 ("query parameter `service` was supplied multiple times"). AP's filter framework treats repeated values per its filter contract; for our typed-equality semantics, repeated = bad.
- **Search never 404s.** A search with no matches returns `200` with an empty `rows`/`hydra:member`/`data` array (per format). 404 is reserved for item operations on a non-existent ID within the configured search window.
- **Time-shorthand precedence.** `since=<duration>` shorthand can only appear without `until`. `since=2h&until=...` (mixed) → 400. Both absolute (`since=<rfc3339>&until=<rfc3339>`) is fine. Only `since=<duration>` (with implicit `until=now`) is fine. No other combinations.
- **HEAD not supported in v1.** Adds test surface for "would `GET` succeed without paying the body" with no clear consumer; skip.
- **OPTIONS / CORS.** Off in v1 (no UI). When a UI shows up, CORS preflight gets added per a future change.
- **Caching headers.** Responses carry `Cache-Control: no-store, private`. We don't set ETags in v1; defer until a real caching consumer asks for them.

**Why.** These choices are mostly mechanical defaults, but writing them down avoids "should we?" debates per endpoint and gives the spec sharp scenario assertions.

### D14. OpenAPI as the consumer-facing contract

**Decision.** The auto-generated OpenAPI 3 spec at `/docs.jsonopenapi` (Swagger UI at `/docs`) is the canonical consumer documentation. Every Resource property, every `#[ApiFilter]`, every `Operation`, every output format, and the bearer-auth security scheme show up automatically. The README's "Reading data" section points consumers at the Swagger UI as the first stop.

The OpenAPI spec is verified in CI: a test loads it and asserts (a) all expected paths are present, (b) all expected filters are present per resource, (c) the security scheme is bearer-token, (d) the spec is valid against OpenAPI 3.1 schema.

**Why.** The whole reason to absorb AP's weight is precisely this. For a no-UI read API, OpenAPI is the consumer onboarding UX. Hand-writing OpenAPI YAML is a chore that drifts; auto-generated stays accurate by construction.

**Why verify in CI.** A regression in a Resource declaration (forgotten filter, wrong type, dropped property) is silent at runtime — clients still get data, but the docs no longer describe it. CI assertion catches the drift.

## Risks / Trade-offs

- **[Risk]** Wide queries are genuinely slower than they would be on DuckDB. A query with no `service` filter over a 24-hour window means PHP evaluates filters row-by-row across every record in those partitions. → **Mitigation:** the mandatory time window (D2) is the primary brake; `limit` caps row materialisation; ULID-ordered file iteration with early-exit gets the best-case fast; tier-ordered predicate evaluation (D12) ensures the cheap filters run first; row-group statistics push down for numeric predicates. Document expected latency: tenant with single-digit-MB partitions is sub-second; tenants pushing tens of MB per hour see seconds for wide windows. Aggregation queries (the workload where DuckDB really shines) are explicitly out of scope. If a workload comes along that genuinely needs richer push-down, that's the trigger to introduce an alternative `ScansParquet` implementation in a follow-up change.
- **[Risk]** A pathological filter (`since=30d`, no service filter, large tenant) materialises a lot of partition globs and reads many files. → **Mitigation:** time window cap; per-request execution timeout (default 10s, configurable via `crashler.read.execution_timeout_seconds`) — when exceeded, return 504 with a "narrow your filters" message. Per-tenant rate limiting is deferred.
- **[Risk]** API Platform overhead per request adds 5–20 ms before any scanning happens. → **Mitigation:** scan time dominates for any non-trivial query. AP's request lifecycle is well-understood; we don't optimise it. Cold-cache responses might feel slow on the first request after a deploy; documented.
- **[Risk]** AP's "Resource" model assumes stable per-record URIs. Logs and metric data-points have none. → **Mitigation:** Resources without item operations (D7) is a supported AP pattern; the docs/ecosystem cope. We don't synthesize fake URIs.
- **[Risk]** Custom OTLP output format on Trace.Get might surprise AP-aware clients that expect the framework's normalized shape. → **Mitigation:** OTLP output is opt-in via `Accept: application/otlp+json`. Default Hydra response works for clients that don't ask.
- **[Risk]** Multiple wire formats × cross-signal links = test surface explosion. → **Mitigation:** format-agnostic test helpers (`assertHasLink($response, $rel, $href)` decodes per format and checks). One link assertion per scenario, not four.
- **[Risk]** `JsonAttributeEquals` requires `json_decode` on `attributes_json` (or `exemplars_json`) per row that survives Tier 2. → **Mitigation:** combine with a `service` and time filter to prune partitions and rows first; document this. Native attribute columns would fix it but require schema changes (deferred). The cost is materialised only for matching rows, not the whole partition.
- **[Risk]** Cursor secret rotation invalidates outstanding cursors mid-pagination. → **Mitigation:** acceptable — cursors are ephemeral, clients should restart from the beginning. Document this in README.
- **[Risk]** flow-php's `Reader` may have edge cases on Parquet files written by flow-php itself but read with different settings. → **Mitigation:** the writer and reader are the same library — round-trip tests on every signal in CI catch regressions.
- **[Trade-off]** No aggregation in v1 means "errors per service" isn't expressible. **Cost:** scripts that want a count have to fetch records and count them. **Benefit:** ships sooner, smaller surface, aggregation gets its own design.
- **[Trade-off]** No multi-attribute filter composition. **Cost:** complex queries need post-filtering on the client. **Benefit:** stays out of DSL territory, criteria stay typed and safe.
- **[Trade-off]** Pure-PHP scanning means a busy tenant with multi-MB-per-hour ingest will see noticeable latency on wide queries. **Cost:** "show me everything from the last 7 days for service X" is seconds-to-tens-of-seconds. **Benefit:** zero deployment complexity, no binary install, no engine config dimension, one execution path. The workloads where this matters most (aggregations, full-history exports) are explicitly out of scope; the workloads it serves well (recent-records browsing, trace-by-id, "what just landed") are the primary v1 consumers.
- **[Trade-off]** Adopting AP adds a ~3–5 MB dependency. **Cost:** longer composer install, larger vendor/. **Benefit:** OpenAPI auto-gen, content negotiation, filter framework, hypermedia rendering, mature ecosystem. For five resources × four formats, AP earns its keep; for a single endpoint it wouldn't.

## Migration Plan

- Additive: no migration. Existing `/v1/logs`, `/v1/traces`, `/v1/metrics` POST endpoints (write side) are untouched. The new GET verbs sit beside them under the same firewall.
- API Platform installation: `composer require api-platform/symfony` runs the recipe; we override the route prefix (`api_platform.yaml` → `route_prefix: /v1`) and the format list. Recipe-installed routes at `/api` (Swagger UI) and `/docs.jsonopenapi` (OpenAPI) stay at their conventional paths.
- Symfony cache:warmup runs as part of the deploy recipe (already configured); AP's compiled metadata + OpenAPI spec are part of the warmed-up cache.
- Production deploy: `dep deploy stage=production` ships the new code; the GET endpoints come up immediately. Nothing to install on the host.
- Rollback: redeploy the previous release tag. Read endpoints disappear; write side is unaffected.

## Open Questions

- **Should `/v1/spans/<span_id>` traverse all partitions when the span isn't found in the recent window, or 404 fast?** Current proposal: 404 fast within the configurable lookback window (default 24h); operators wanting deeper lookup pass an explicit `since` param. Re-evaluate based on early usage.
- **Multi-attribute filter composition (`attribute.foo=x&attribute.bar=y`).** Useful, but each one requires a separate JSON walk per surviving row. Defer to a follow-up after v1 ships and we have data on real query patterns.
- **`POST /v1/<signal>/search` for complex criteria.** Deferred — no consumer asking for it. The Loki hybrid pattern (GET for simple, POST for complex) is the natural follow-up if URL length or filter composition becomes a real constraint.
- **GraphQL.** AP supports it with one config flag. Not turned on in v1 because the operator-first surface is REST. Re-evaluate when a UI/agent consumer materializes.
- **`Accept: application/x-protobuf` for read responses.** Symmetric with the write side, useful for OTel-aware clients. Defer — JSON-only in v1; revisit if a real consumer asks.
- **JSON:API format for completeness.** AP supports it; we list it as available. Worth shipping or worth dropping? Probably ship — the cost is one format-registration line and clients that already speak JSON:API get a working shape for free.
