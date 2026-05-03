## Why

Crashler ingests logs in production. The `refactor-multi-signal-receiver` change extracted every signal-generic concern (HTTP pipeline, schema catalog, attribute extractor, partition path, atomic write, tenant model) into reusable scaffolding precisely so adding the next OTLP signal is a thin slice of *signal-specific* code rather than another full subsystem build.

This change adds OpenTelemetry traces. Any OTel SDK or Collector pointing its `otlphttp` exporter at the same host, port, and tenant token can now POST trace data alongside logs and have it land as Parquet under `traces/<tenant>/…`.

Like logs, the on-disk schema is treated as **internal**: the planned query layer will be the public read contract; ad-hoc DuckDB queries are operator tooling.

## What Changes

- New `POST /v1/traces` endpoint accepting OTLP/HTTP request bodies in either `application/json` (proto3-JSON) or `application/x-protobuf` (binary), with optional `Content-Encoding: gzip`. Returns the OTLP `ExportTraceServiceResponse` shape (`{}` on success). Authentication is the same bearer-token model used by `/v1/logs` — same tenant, same token registry.
- New trace DTO tree mirroring `App\Otlp\Dto\*` for logs: `ExportTraceServiceRequestDto`, `ResourceSpansDto`, `ScopeSpansDto`, `SpanDto`, `SpanEventDto`, `SpanLinkDto`, `SpanStatusDto`. Reuses the existing `KeyValueDto` and `AnyValueDto`.
- Two new decoders, `TracesJsonDecoder` and `TracesProtobufDecoder`, both implementing the existing `App\Otlp\Contract\SignalDecoder` interface.
- New `TracesIngestService` implementing `App\Otlp\Contract\IngestsSignal`. Wires through the existing `OtlpRequestPipeline`. One Parquet row per span. `events_json` and `links_json` columns carry their nested arrays as JSON strings (per the schema-as-internal stance — promotion to typed nested columns is a future change).
- New `OtlpTracesController` — five-line delegator into the pipeline, mirroring `OtlpLogsController`.
- New `config/schemas/traces/v1.yaml` schema covering 42 columns: required span identity + timing, optional W3C trace-context fields, the same Tier-1 universal resource columns as `logs/v1`, scope name/version/schema_url, and 13 Tier-2 record-level promotions targeting hot HTTP/database/messaging/RPC/error semantic-convention keys.
- `duration_nano` is computed at ingest time as `end_time_unix_nano - start_time_unix_nano` and stored as a top-level int64 column so range queries don't need arithmetic in the WHERE clause.
- `kind` (int32) and `kind_text` (string) both carried per row, mirroring the `severity_number` / `severity_text` pair on logs. Same pattern for `status_code` (int32) + `status_text` (string).
- `services.yaml` adds a second `AttributeColumnExtractor` instance via `AttributeColumnExtractorFactory::forSignal('traces')`, plus a `ParquetFileWriter` for the traces signal.
- A functional test posts a span via both encodings and reads back the resulting Parquet file to confirm the full schema.

## Capabilities

### New Capabilities
- `trace-ingest`: HTTP API for receiving OTLP/HTTP trace payloads (JSON + protobuf, optional gzip), authenticating them against the existing tenant registry, decoding into the trace DTO tree, and synchronously dispatching to the storage layer. Mirrors the `log-ingest` shape.
- `trace-storage`: per-request Parquet write under `<storage-root>/traces/<tenant>/date=YYYY-MM-DD/hour=HH/part-<ulid>.parquet`. Atomic `.tmp + rename`. Schema driven by `config/schemas/traces/v1.yaml` via the existing schema catalog. One row per span; events and links serialized as JSON-string columns.

### Modified Capabilities
<!-- None. The schema-catalog and tenants capabilities are reused unchanged; the existing log-ingest and log-storage capabilities remain unmodified. -->

## Impact

- **No new runtime dependencies.** The OTel proto types for traces are already in vendor (transitively via `open-telemetry/gen-otlp-protobuf`). flow-php, Symfony, zenstruck — all reused.
- **No HTTP changes to the existing `/v1/logs` surface.** Auth, content-type rules, response shapes, and existing functional tests are untouched.
- **New on-disk directory tree** under `<storage-root>/traces/`. Empty until first request lands; coexists with `<storage-root>/logs/`.
- **No schema-breaking changes** to existing files. `logs/v1.yaml` is unchanged; the traces YAML is purely additive.
- **No deploy gymnastics.** Standard `dep deploy production` — no purge flag needed since we're not migrating data.
- **Test surface grows.** Estimated +60 unit + component + functional tests covering the new decoders, ingest service, controller, and end-to-end Parquet round-trips.
- **Out of scope (deferred to follow-up changes)**:
  - Span events / links promoted to first-class rows. v1 keeps them in JSON columns; if event-level queries become a hot path, a future change adds a parallel `events/<tenant>/…` Parquet tree.
  - OTLP metrics — separate change.
  - Per-trace lookup index. Trace-ID-keyed queries today scan the partition; a future change can add a small index file per partition or move to a real columnar engine.
  - Sampling / drop rules — they belong in the future `add-ingest-transforms` change.
  - Compaction, retention, S3 backend — same deferral as the log change.
