## Why

The logs pipeline is shipping in production, and the next step is supporting OTLP traces and metrics. About 80% of the log code is signal-generic (HTTP auth, Content-Type dispatch, gzip, size limits, atomic Parquet writes, partition layout, error response shape) and 20% is signal-specific (decoders, DTO flattening, column layout). Adding traces and metrics by copy-pasting the controller and ingest service would triple the surface area of the generic 80%.

This change extracts that 80% into a reusable pipeline, then introduces a YAML-driven schema catalog so each signal's column layout (and, eventually, ingest-time transforms) is described in config rather than hand-coded. As a follow-on, we promote OpenTelemetry semantic-convention attributes to first-class columns with a `resource_` / `scope_` prefix convention so query writers don't have to reach into JSON blobs for the hot path.

The change deliberately keeps the runtime behaviour of `POST /v1/logs` unchanged — same auth, same status codes, same response shapes. The internal Parquet schema does change (column renames + new optional columns), but the deployed schema is treated as an internal contract; readers go through a query layer (planned, not in this change).

## What Changes

- New **schema catalog**: schema definitions live in `config/schemas/<signal>/v<n>.yaml`, parsed at boot, validated at compile time, exposed as `App\Schema\SchemaCatalog`. Each schema declares its columns, semantic-convention promotion rules, and a reserved `transforms:` block (validated as empty in this change; populated by future ones).
- New **OTLP request pipeline**: `App\Otlp\OtlpRequestPipeline` owns the signal-generic ingest path (Content-Type dispatch, gzip, size limits, JSON `{"message": …}` error responses, OTLP `ExportXxxServiceResponse` shape). Signals plug in by providing a JSON decoder, a protobuf decoder, and an ingest service.
- New **attribute column extractor**: `App\Otlp\AttributeColumnExtractor` reads promotion rules from a `SchemaDefinition` and converts a `KeyValueDto[]` list into a map of promoted column values, leaving the original list intact for the JSON blob.
- **Logs schema migration** (BREAKING for the internal Parquet layout, not for HTTP): the existing `service_name` column is renamed `resource_service_name`. New optional columns added: `resource_service_namespace`, `resource_service_version`, `resource_service_instance_id`, `resource_deployment_environment`, `resource_host_name`, `resource_telemetry_sdk_language`, `scope_schema_url`, `event_name`, `exception_type`, `exception_message`. Two new universal columns identify the schema each row belongs to: `_schema_version` (int32) and `_schema_id` (string).
- **Per-file Parquet metadata**: when flow-php supports it, every written Parquet file carries `crashler.schema_id`, `crashler.schema_version`, and `crashler.schema_yaml_sha256` keys in its file-level key/value metadata. If the writer API doesn't expose this, we ship row-level columns only.
- **Existing prod files dropped at deploy time**: the live host has roughly a dozen smoke-test Parquet files under `var/share/logs/default/`; the deploy task removes them before the new schema goes live (option α). The data has zero retention value.
- `App\Storage\PartitionPathResolver::resolve()` gains a `string $signalSubdir` parameter so the same resolver serves logs, traces, and metrics. Today's call sites pass `'logs'`.
- The existing `OtlpLogsController` is rewritten as a thin delegator into `OtlpRequestPipeline`; behaviour is unchanged.

## Capabilities

### New Capabilities
- `schema-catalog`: YAML-driven, versioned schema definitions for every signal's Parquet layout, including column lists, semantic-convention promotion rules, and a reserved transforms block. Validated at compile time; loaded once at boot.

### Modified Capabilities
- `log-storage`: the Parquet schema (column list, types, repetitions) is no longer defined in PHP — it is loaded from `config/schemas/logs/v1.yaml`. The shape of a written row changes: `service_name` is renamed `resource_service_name` and ten new optional columns are added (full list above). Two universal `_schema_version` / `_schema_id` columns are emitted on every row. File-level Parquet metadata carries the schema identity where the writer API exposes it.

## Impact

- **No new runtime dependencies**. The schema YAML loader uses `symfony/yaml` (already in deps); reflection/parsing of attribute values uses existing types.
- **Internal Parquet schema breaks** for the `logs` signal (this is the entire point). The live host has only smoke-test files; they're dropped at deploy. Documented as the canonical schema-evolution pattern (option α: rewrite-or-drop) in `design.md`.
- **No HTTP API changes**: `POST /v1/logs` accepts the same Content-Types, returns the same responses, validates the same way. Existing functional tests pass without modification (asserting status codes and JSON envelope shapes); only tests that read the produced Parquet rows back are updated for the new column names.
- **Tests grow but stay green**: 186 tests today. The refactor adds ~40 unit + component tests for the schema catalog, attribute extractor, and pipeline, plus updates ~10 existing tests for the new column names.
- **Spec living surface grows**: a new `schema-catalog` capability spec joins the three existing ones. `log-storage` gets a `MODIFIED Requirement: Parquet schema and types` delta.
- **Out of scope (deferred to follow-up changes)**:
  - Implementing the `transforms:` block (just reserved here). Tier-1 declarative ops (drop_keys, redact_keys, defaults) land in a future `add-ingest-transforms` change.
  - Adding the trace and metric signals — those are `add-otlp-trace-ingest` and `add-otlp-metric-ingest`, both built on top of this refactor.
  - The query layer / read API — when a custom UI is built, it'll get its own change; for now reads stay ad-hoc DuckDB.
  - Compaction, retention, S3 backend.
