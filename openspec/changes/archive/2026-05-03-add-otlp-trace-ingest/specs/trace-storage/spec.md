## ADDED Requirements

### Requirement: Storage root and signal subdirectory

The system SHALL write trace Parquet files under `<storage-root>/traces/`, where `<storage-root>` is the same directory used for the logs signal (resolved from `APP_SHARE_DIR` with a default of `<project>/var/share`). A separate `traces/` directory SHALL coexist with the existing `logs/` directory under the same root; tenant separation continues to be a security boundary while signal separation is operational.

#### Scenario: Traces and logs share storage root
- **WHEN** both `/v1/logs` and `/v1/traces` accept requests for tenant `acme`
- **THEN** the resulting files land under `<storage-root>/logs/acme/…` and `<storage-root>/traces/acme/…` respectively
- **AND** neither writer's files appear in the other's directory tree

### Requirement: On-disk layout

For every accepted request, the system SHALL write exactly one Parquet file at `<storage-root>/traces/<tenant_slug>/date=<YYYY-MM-DD>/hour=<HH>/part-<ulid>.parquet`. The `<tenant_slug>` segment SHALL be the slug of the authenticated tenant. `<YYYY-MM-DD>` and `<HH>` SHALL be derived from the request's wall-clock arrival time interpreted as UTC. `<ulid>` SHALL be a Crockford-base32 ULID generated at file-creation time so directory listings sort by creation order. Parent directories SHALL be created with mode 0750 if they do not already exist.

#### Scenario: Tenant directory used
- **WHEN** a request is accepted for tenant `acme`
- **THEN** the resulting Parquet file's path begins with `<storage-root>/traces/acme/`

#### Scenario: Hive partition layout from ingest time
- **WHEN** a request arrives at 2026-05-03 14:37 UTC
- **THEN** the resulting Parquet file lives at `…/<slug>/date=2026-05-03/hour=14/part-<ulid>.parquet`
- **AND** this is true regardless of any spans' own `start_time_unix_nano` values

#### Scenario: One file per accepted request
- **WHEN** a request contains spans with `start_time_unix_nano` values spanning multiple event-time hours
- **THEN** all spans are still written to a single Parquet file selected by ingest time
- **AND** each row's `start_time_unix_nano` and `end_time_unix_nano` columns reflect each span's actual event times

### Requirement: Atomic file commit via .tmp + rename

The system SHALL write each Parquet file to `<final-path>.tmp`, close the writer, fsync the underlying file descriptor, and `rename()` the file to `<final-path>`. The HTTP 200 response SHALL only be sent after the rename returns successfully. If any step fails, the `.tmp` file SHALL be unlinked before the request returns 5xx.

#### Scenario: Reader never observes partial file
- **WHEN** the handler is mid-write
- **THEN** no file at the final destination path is visible to other processes until the writer is closed and renamed

#### Scenario: Failed write leaves no orphan
- **WHEN** the Parquet write or rename fails for any reason
- **THEN** the request returns 5xx
- **AND** no `.tmp` file remains on disk for this request

### Requirement: Parquet schema and types

Each Parquet file SHALL be written using the column layout defined by the `traces/v1` schema in the schema catalog (`config/schemas/traces/v1.yaml`). The full row shape produced by the writer is:

| column                              | type   | repetition | source                                                                  |
| ----------------------------------- | ------ | ---------- | ----------------------------------------------------------------------- |
| `trace_id_hex`                      | string | REQUIRED   | Span.trace_id (lowercase 32-char hex)                                    |
| `span_id_hex`                       | string | REQUIRED   | Span.span_id (lowercase 16-char hex)                                     |
| `name`                              | string | REQUIRED   | Span.name                                                                |
| `start_time_unix_nano`              | int64  | REQUIRED   | Span.start_time_unix_nano                                                |
| `end_time_unix_nano`                | int64  | REQUIRED   | Span.end_time_unix_nano                                                  |
| `duration_nano`                     | int64  | REQUIRED   | derived: end_time_unix_nano - start_time_unix_nano                       |
| `kind`                              | int32  | REQUIRED   | SpanKind enum integer value (0..5)                                       |
| `kind_text`                         | string | REQUIRED   | SpanKind name (`UNSPECIFIED`, `INTERNAL`, `SERVER`, `CLIENT`, `PRODUCER`, `CONSUMER`) |
| `resource_attributes_json`          | string | REQUIRED   | full ResourceSpans.resource.attributes as JSON                           |
| `attributes_json`                   | string | REQUIRED   | full Span.attributes as JSON                                             |
| `events_json`                       | string | REQUIRED   | Span.events serialized as JSON list (defaults to `[]`)                   |
| `links_json`                        | string | REQUIRED   | Span.links serialized as JSON list (defaults to `[]`)                    |
| `parent_span_id_hex`                | string | optional   | Span.parent_span_id (lowercase 16-char hex when present)                  |
| `trace_state`                       | string | optional   | Span.trace_state                                                         |
| `flags`                             | int32  | optional   | Span.flags                                                               |
| `status_code`                       | int32  | optional   | SpanStatus enum integer (UNSET=0, OK=1, ERROR=2)                         |
| `status_text`                       | string | optional   | SpanStatus name (`UNSET`, `OK`, `ERROR`)                                 |
| `status_message`                    | string | optional   | SpanStatus.message                                                       |
| `dropped_attributes_count`          | int32  | optional   | Span.dropped_attributes_count                                             |
| `dropped_events_count`              | int32  | optional   | Span.dropped_events_count                                                 |
| `dropped_links_count`               | int32  | optional   | Span.dropped_links_count                                                  |
| `resource_service_name`             | string | optional   | promoted: `service.name`                                                  |
| `resource_service_namespace`        | string | optional   | promoted: `service.namespace`                                             |
| `resource_service_version`          | string | optional   | promoted: `service.version`                                               |
| `resource_service_instance_id`      | string | optional   | promoted: `service.instance.id`                                           |
| `resource_deployment_environment`   | string | optional   | promoted: `deployment.environment.name` or legacy `deployment.environment` |
| `resource_host_name`                | string | optional   | promoted: `host.name`                                                     |
| `resource_telemetry_sdk_language`   | string | optional   | promoted: `telemetry.sdk.language`                                        |
| `scope_name`                        | string | optional   | ScopeSpans.scope.name                                                     |
| `scope_version`                     | string | optional   | ScopeSpans.scope.version                                                  |
| `scope_schema_url`                  | string | optional   | ScopeSpans.schema_url                                                     |
| `http_request_method`               | string | optional   | promoted record-level: `http.request.method`                              |
| `http_response_status_code`         | int32  | optional   | promoted record-level: `http.response.status_code`                        |
| `http_route`                        | string | optional   | promoted record-level: `http.route`                                       |
| `url_scheme`                        | string | optional   | promoted record-level: `url.scheme`                                       |
| `db_system_name`                    | string | optional   | promoted record-level: `db.system.name`                                   |
| `db_collection_name`                | string | optional   | promoted record-level: `db.collection.name`                               |
| `messaging_system`                  | string | optional   | promoted record-level: `messaging.system`                                 |
| `messaging_destination_name`        | string | optional   | promoted record-level: `messaging.destination.name`                       |
| `rpc_service`                       | string | optional   | promoted record-level: `rpc.service`                                      |
| `rpc_method`                        | string | optional   | promoted record-level: `rpc.method`                                       |
| `error_type`                        | string | optional   | promoted record-level: `error.type`                                       |
| `code_function`                     | string | optional   | promoted record-level: `code.function`                                    |
| `code_namespace`                    | string | optional   | promoted record-level: `code.namespace`                                   |

In addition, every row carries the universal `_schema_version` (int32 REQUIRED, value `1`) and `_schema_id` (string REQUIRED, value `traces/v1`) columns appended by the writer per the `schema-catalog` capability.

Promoted-column values are *shadows*: every promoted attribute remains in `resource_attributes_json` or `attributes_json` unchanged. `events_json` and `links_json` carry their entire OTel sub-message verbatim so events / links can be promoted to first-class rows in a future change without losing data.

All Parquet column types SHALL be primitive in this version; native `map<string, string>` and `list<struct>` are explicitly out of scope.

#### Scenario: Schema columns present
- **WHEN** any Parquet file produced by the trace writer is opened by a reader
- **THEN** all columns above are present with the documented types and repetitions
- **AND** the file's row schema also exposes `_schema_version` (int32 REQUIRED) and `_schema_id` (string REQUIRED)

#### Scenario: Resource attributes denormalised onto every row, plus promoted columns
- **WHEN** a request contains one ResourceSpans block with N spans whose resource attributes include `service.name=checkout` and `host.name=node-1`
- **THEN** the resulting Parquet file contains N rows
- **AND** every row has the same `resource_attributes_json` (the full JSON of the resource attributes)
- **AND** every row has `resource_service_name = 'checkout'` and `resource_host_name = 'node-1'`
- **AND** the values still appear inside `resource_attributes_json` (shadow promotion, not move)

#### Scenario: duration_nano is computed at ingest
- **WHEN** a span has `start_time_unix_nano = 1000000000000` and `end_time_unix_nano = 1000050000000`
- **THEN** the row's `duration_nano` column is `50000000`

#### Scenario: kind and kind_text both populated
- **WHEN** a span's kind is `SPAN_KIND_SERVER` (proto enum 2)
- **THEN** the row's `kind` column is `2`
- **AND** the row's `kind_text` column is `SERVER`

#### Scenario: status_code, status_text, status_message tracked together
- **WHEN** a span's status is `{code: STATUS_CODE_ERROR, message: 'connection refused'}`
- **THEN** the row's `status_code` is `2`, `status_text` is `ERROR`, and `status_message` is `'connection refused'`

#### Scenario: HTTP semconv attributes promoted
- **WHEN** a span's attributes contain `http.request.method=POST`, `http.response.status_code=500`, and `http.route=/orders/:id`
- **THEN** the row's `http_request_method = 'POST'`, `http_response_status_code = 500`, `http_route = '/orders/:id'`
- **AND** the original keys still appear inside `attributes_json`

#### Scenario: Events and links carried as JSON arrays
- **WHEN** a span has two events and one link
- **THEN** the row's `events_json` is a valid JSON array of length 2
- **AND** the row's `links_json` is a valid JSON array of length 1
- **AND** when the span has no events or links, the columns hold `[]` (not NULL)

#### Scenario: Empty parent_span_id becomes NULL
- **WHEN** a span has no parent (root span)
- **THEN** the row's `parent_span_id_hex` column is NULL (or absent), not an empty string

#### Scenario: Universal _schema_id reflects the schema used
- **WHEN** any Parquet file produced for the traces signal is opened
- **THEN** every row carries `_schema_version = 1` and `_schema_id = 'traces/v1'`

### Requirement: Compression configuration

The trace writer SHALL use the same compression codec configured for the rest of Crashler (`CRASHLER_PARQUET_COMPRESSION` environment variable). The default is `GZIP`. If the configured codec requires a PHP extension that is not loaded, the application SHALL fail fast at boot rather than at write time.

#### Scenario: Default GZIP compression
- **WHEN** `CRASHLER_PARQUET_COMPRESSION` is unset
- **THEN** trace Parquet files are written with GZIP compression

### Requirement: Memory-bounded row groups

The trace writer SHALL configure flow-php's `ROW_GROUP_SIZE_BYTES` option to a value bounded such that a single request's spans (typically tens to a few hundred per OTel batch) do not exceed the worker's PHP `memory_limit`. The default SHALL be 32 MiB, matching the logs signal.

#### Scenario: Row-group size respected
- **WHEN** a request contains spans whose serialized form exceeds the row-group size
- **THEN** the writer flushes multiple row groups during the request
- **AND** the handler's peak memory usage stays bounded

### Requirement: No background workers or write-ahead log for traces

The system SHALL NOT include a write-ahead log table, a flush worker, a console command for flushing, or any other process that runs outside of the HTTP request lifecycle for trace ingest in this change. All trace Parquet write activity SHALL occur synchronously in the request that produced the data.

#### Scenario: No flush command exists for traces
- **WHEN** an operator lists registered Symfony console commands
- **THEN** no command for flushing, draining, or compacting trace Parquet files is registered
