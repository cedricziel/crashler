## Context

`refactor-multi-signal-receiver` (archived 2026-05-03) extracted everything that's signal-generic in the OTLP receiver: the HTTP pipeline, the schema catalog, the attribute-column extractor, the partition path resolver, the atomic Parquet writer, the universal `_schema_*` columns. Adding traces is now a slice along three axes:

1. A new schema YAML (`config/schemas/traces/v1.yaml`) declaring the trace row shape and promotion rules.
2. Signal-specific decoder pair + DTO tree + ingest service.
3. A thin controller that wires the three above into the pipeline.

The interesting design decisions for traces are *content* decisions (which OTel semconv keys to promote, how to handle SpanKind / SpanStatus enums, what to do with span events and links) rather than scaffolding decisions.

## Goals / Non-Goals

**Goals:**

- POST /v1/traces accepts OTLP/HTTP-JSON and OTLP/HTTP-protobuf, with optional gzip, returns OTLP `ExportTraceServiceResponse` shape (`{}` on success). Same auth, same size limits, same error envelope as logs.
- One Parquet row per Span. Resource attributes denormalized onto every row (consistent with logs).
- Promote the seven Tier-1 universal resource attributes to the same column names already used by logs (`resource_service_name`, `resource_host_name`, etc.) so cross-signal joins are obvious.
- Promote 13 Tier-2 record-level semconv keys (HTTP/db/messaging/RPC/error/code families) to top-level columns. Everything else stays in `attributes_json`.
- `duration_nano` materialised at ingest. `kind` (int32) + `kind_text` (string) both carried. `status_code` (int32) + `status_text` (string) both carried.
- Events and links carried as JSON-string columns (`events_json`, `links_json`). Lossless: the entire OTel proto sub-message is serialised so a future change can lift events to first-class rows without losing data.
- Adding the next signal (metrics) after this should be the same shape and complexity.

**Non-Goals:**

- Span events / links flattened to first-class Parquet rows. Deferred. JSON-string is fine for v1's "view the trace, see events inline" pattern.
- A trace-ID lookup index. v1 query path globs the partition; a future change can add a per-partition index when this becomes hot.
- Native Parquet `map<string, string>` for attributes. Same deferral as logs/v1.
- A per-signal authenticator or firewall — the existing `IngestTokenAuthenticator` works as-is because the firewall pattern is already `^/v1/`.
- Behaviour changes to `/v1/logs`.
- Sampling, redaction, or any ingest-time transform. Reserved in the YAML's transforms block, implemented by the future `add-ingest-transforms` change.

## Decisions

### D1. One row per span; events and links as JSON-string columns

**Decision.** Each Span in the request becomes one Parquet row. The Span's `events` array (zero or more `SpanEvent` messages, each with `name`, `time_unix_nano`, `attributes`, `dropped_attributes_count`) is serialised to a single JSON string and stored in the `events_json` column. Same for `links` → `links_json`. The serialisation uses the OTLP/HTTP-JSON wire shape (canonical proto3-JSON) so the data round-trips exactly and downstream consumers can read it with any OTLP-aware parser.

**Why.** Events and links are mostly diagnostic detail viewed alongside their parent span. Most queries filter spans, then surface events/links from the matched rows. Flattening to one row per event would create a separate file tree and break the "one row per span" mental model. JSON-string columns mirror what we already do for `attributes_json` and `resource_attributes_json` and let DuckDB queries reach them with `json_extract` when needed.

**Alternatives considered.**

- *Flatten events to a parallel `traces/events/<tenant>/…` Parquet tree.* Adds a second writer per request, complicates "one file per request" semantics, and mostly benefits a query pattern (event-level filters across spans) we don't have evidence for yet.
- *Native Parquet `list<struct>`.* flow-php's writer supports nested types, but our existing AnyValue handling is JSON-string for fidelity reasons; switching just events to nested types would create inconsistency.

### D2. `duration_nano` computed at ingest time

**Decision.** The ingest service derives `duration_nano = end_time_unix_nano - start_time_unix_nano` and writes it as a top-level int64 REQUIRED column.

**Why.** Range queries ("spans slower than 100ms in the last hour") become a single column predicate instead of arithmetic on two columns. The cost is one int64 per row; Parquet's column compression makes that nearly free. Negative durations (clock skew across producers) are stored as-is — out-of-band concern; we don't try to clamp.

### D3. SpanKind and SpanStatus carry both numeric and text columns

**Decision.** SpanKind is stored as `kind` (int32 REQUIRED, the proto enum value 0–5) AND `kind_text` (string REQUIRED, one of `UNSPECIFIED`, `INTERNAL`, `SERVER`, `CLIENT`, `PRODUCER`, `CONSUMER`). Same pattern for SpanStatus: `status_code` (int32 OPTIONAL, 0–2) + `status_text` (string OPTIONAL, `UNSET`/`OK`/`ERROR`) + `status_message` (string OPTIONAL, the operator-supplied message).

**Why.** Mirrors what `logs/v1` does for `severity_number` + `severity_text`. Numeric column gives compact ordering and group-by; text column gives readable filters without a CASE expression. Cost is negligible (low-cardinality string compresses to ~bytes per file with dictionary encoding).

### D4. Tier-1 universal columns are byte-for-byte the same names as `logs/v1`

**Decision.** `resource_service_name`, `resource_service_namespace`, `resource_service_version`, `resource_service_instance_id`, `resource_deployment_environment`, `resource_host_name`, `resource_telemetry_sdk_language` — all identical to logs. Same goes for `scope_name`, `scope_version`, `scope_schema_url`. Promotion rules use the same canonical-then-legacy ordering for `deployment.environment.name` / `deployment.environment`.

**Why.** A query that filters or joins across logs and traces by `resource_service_name = 'checkout'` should "just work" without per-signal column-rename gymnastics. This is also the cheapest possible decision because the YAML rules are already known good — we're just copying the block.

### D5. Tier-2 record-level promotions: HTTP, DB, messaging, RPC, error, code

**Decision.** Promote 13 semconv keys to top-level columns:

```
http.request.method            → http_request_method        (string)
http.response.status_code      → http_response_status_code  (int32)
http.route                     → http_route                 (string)
url.scheme                     → url_scheme                 (string)
db.system.name                 → db_system_name             (string)
db.collection.name             → db_collection_name         (string)
messaging.system               → messaging_system           (string)
messaging.destination.name     → messaging_destination_name (string)
rpc.service                    → rpc_service                (string)
rpc.method                     → rpc_method                 (string)
error.type                     → error_type                 (string)
code.function                  → code_function              (string)
code.namespace                 → code_namespace             (string)
```

**Why these and not others.** The list targets "what filters my dashboard queries already write". Specifically:

- `http.request.method` + `http.response.status_code` + `http.route` capture the HTTP query primary keys (status, route grouping). `url.scheme` is low-cardinality and useful.
- Deliberately *omitted*: `url.path` and `url.full` — high cardinality, defeats dictionary encoding.
- `db.system.name` + `db.collection.name` cover the "queries to MySQL hitting users table" filter. Deliberately *omitted*: `db.query.text` (high cardinality, often very long) and `db.query.summary` (less stable and overlaps with span name).
- `messaging.system` + `messaging.destination.name`: queue / topic / stream filters.
- `rpc.service` + `rpc.method`: the gRPC analogue of HTTP method+route.
- `error.type` is the cross-cutting "what kind of error" filter.
- `code.function` + `code.namespace` enable stack-frame-style queries when SDKs emit them; deliberately *omitted*: `code.filepath` and `code.lineno` (line-level changes invalidate dictionary entries).

**Migration policy if this list turns out wrong.** Schema-as-internal means a future change can add or remove columns by bumping `traces/v2.yaml` without breaking existing files. Conservative bias: ship the wider list; trim later only if a column is truly never queried.

### D6. Trace IDs are stored as lowercase hex strings, mirroring logs

`trace_id_hex` (32 chars, REQUIRED), `span_id_hex` (16 chars, REQUIRED), `parent_span_id_hex` (16 chars, OPTIONAL). The OTLP proto carries them as raw bytes; the JSON wire form already uses hex; Crashler uses hex everywhere on disk for consistency with the logs schema and so DuckDB string filters work without binary coercion.

### D7. SpanEvent and SpanLink JSON shape

**Decision.** `events_json` is a JSON array of `{name, timeUnixNano, attributes, droppedAttributesCount}` objects. `links_json` is a JSON array of `{traceId, spanId, traceState, attributes, droppedAttributesCount, flags}` objects. Field names use camelCase to match OTLP/HTTP-JSON conventions; `timeUnixNano` is a numeric string per int64 JS-precision rules; `attributes` is the full KeyValue list; `traceId`/`spanId` are lowercase hex.

**Why this shape.** It's exactly what an OTel SDK exporting in `application/json` mode would produce. Any future tooling parsing these JSON blobs reuses the same decoders.

### D8. Per-signal AttributeColumnExtractor, wired by the existing factory

**Decision.** `AttributeColumnExtractorFactory::forSignal('traces')` builds the extractor for the traces signal at boot. `services.yaml` adds the wiring next to the existing `'logs'` instance. The two instances are independent objects (each holds its own `SchemaDefinition`).

**Why.** Avoids cross-signal coupling at runtime; each ingest service injects its own extractor. Future signals add another factory line.

### D9. Per-signal ParquetFileWriter via the existing factory

**Decision.** `ParquetFileWriterFactory::create('traces')` is added; called from a new `App\Storage\TraceParquetFileWriter` alias-or-service. The factory is already parameterised by signal name.

**Why.** Same as D8: one writer instance per signal so log writes and trace writes don't share state.

### D10. New `OtlpTracesController` mirrors `OtlpLogsController`

**Decision.** A separate controller class with its own `#[Route('/v1/traces', methods: ['POST'])]` attribute, constructor-injecting the trace decoders + ingest service + the shared pipeline.

**Why.** Same argument as the refactor: separate `#[Route]` attributes keep `bin/console debug:router` readable; composition (calling into the pipeline) means there's no shared base class to grow methods.

## Risks / Trade-offs

- **Schema bloat.** 42 columns per row is on the wide side. Mitigation: most rows have many NULLs and Parquet's column-pruning only reads what queries reference. Storage cost per NULL is negligible (RLE + dictionary encoding).
- **Events-as-JSON limits queryability.** A query like "spans whose db.query event lasted >100ms" requires `json_extract` and a custom function. Mitigation: documented as a known limitation; events promotion is a tracked future change.
- **Cross-tenant trace-ID lookups are slow without an index.** Mitigation: trace-ID lookup is one of the patterns the planned query layer will optimize. For v1, a query layer is out of scope; tenant-scoped path globs are still tractable for typical-volume tenants.
- **Promoted column list might be wrong for someone's workload.** Mitigation: schema-as-internal; bump `traces/v2.yaml` to add/remove columns. The JSON blob is the lossless source of truth, so column changes never lose data.
- **Span timing semantics under clock skew.** `duration_nano` can be negative if `end_time < start_time` (cross-host clocks). We don't clamp; queries that care can `WHERE duration_nano > 0`. Mitigation: documented as a known limitation; OTel SDKs are responsible for sane timestamps.
- **High-volume span streams.** A single request can carry hundreds of spans; a single OTel Collector can fire many requests/sec. v1 produces one Parquet file per request, same small-files concern as logs. Mitigation: same deferred compaction story.

## Migration Plan

Additive change. Steps:

1. Land code on `main`. CI runs the new test suites alongside the existing ones.
2. `dep deploy production`. No env flags to set; no data to purge.
3. After deploy, smoke-test a span POST against `/v1/traces` with the existing tenant token. Verify a Parquet file lands at `traces/<slug>/date=…/hour=…/part-…`.
4. Optionally point an OTel SDK at `https://crashler.cedric-ziel.com/v1/traces` (same `OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer cw_…`) and confirm spans flow.

Rollback: revert the change-2 commits and redeploy. Files written under `traces/` remain on disk but become orphaned (still readable; the controller route just goes away). No data loss.

## Open Questions

- **Should we add a `trace_root` boolean column** (parent_span_id is empty, indicating a root span)? Most trace dashboards filter to roots constantly. It's derived from `parent_span_id_hex IS NULL OR parent_span_id_hex = ''`. Inclination: no, computable at query time; revisit if it becomes a hot pattern.
- **Should `dropped_*_count` columns default to 0 instead of NULL** when the proto sets them to 0? OTLP semantics say 0 means "no drops"; NULL would mean "unknown". Probably store 0 → 0 explicitly. Resolve in implementation.
- **Should OtlpRequestPipeline's "Internal error while persisting signal data" message be more specific per signal**? Probably no — this leaks no detail by design.
