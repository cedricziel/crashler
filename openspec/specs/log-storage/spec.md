## Purpose

Defines how accepted OTLP log records are persisted to local disk as Hive-partitioned Parquet files. Each accepted request produces exactly one Parquet file under `<storage-root>/logs/<tenant-slug>/date=YYYY-MM-DD/hour=HH/part-<ulid>.parquet`. Files commit atomically via `.tmp` + rename, are written synchronously inside the request handler, and persist across deploys via a shared volume. Tenant directory separation is treated as a security boundary, not a query optimisation.

## Requirements

### Requirement: Storage root configuration

The system SHALL resolve its storage root from the `APP_SHARE_DIR` environment variable. The application SHALL fail fast at boot if `APP_SHARE_DIR` is unset, refers to a non-existent directory, or is not writable by the web-server process.

#### Scenario: Missing APP_SHARE_DIR fails fast
- **WHEN** the application boots without `APP_SHARE_DIR` set
- **THEN** boot fails with a clear error before any HTTP listener is opened

#### Scenario: Unwritable storage root fails fast
- **WHEN** `APP_SHARE_DIR` points to a directory the worker user cannot write to
- **THEN** boot fails with a clear error

### Requirement: On-disk layout

For every accepted request, the system SHALL write exactly one Parquet file at `<APP_SHARE_DIR>/logs/<tenant_slug>/date=<YYYY-MM-DD>/hour=<HH>/part-<ulid>.parquet`. The `<tenant_slug>` segment SHALL be the slug of the authenticated tenant. `<YYYY-MM-DD>` and `<HH>` SHALL be derived from the request's wall-clock arrival time interpreted as UTC. `<ulid>` SHALL be a Crockford-base32 ULID generated at file-creation time so directory listings sort by creation order. Parent directories SHALL be created with mode 0750 if they do not already exist.

#### Scenario: Tenant directory used
- **WHEN** a request is accepted for tenant `acme`
- **THEN** the resulting Parquet file's path begins with `<APP_SHARE_DIR>/logs/acme/`

#### Scenario: Hive partition layout from ingest time
- **WHEN** a request arrives at 2026-05-03 14:37 UTC
- **THEN** the resulting Parquet file lives at `…/<slug>/date=2026-05-03/hour=14/part-<ulid>.parquet`
- **AND** this is true regardless of the records' own `time_unix_nano` values

#### Scenario: ULID filenames sort lexically
- **WHEN** two requests for the same tenant complete in the same hour at times t1 < t2
- **THEN** the file produced for t1 sorts lexicographically before the file produced for t2

#### Scenario: One file per accepted request
- **WHEN** a request contains records spanning multiple event-time hours
- **THEN** all records are still written to a single Parquet file selected by ingest time
- **AND** the file's `time_unix_nano` column reflects each record's actual event time

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

Each Parquet file SHALL contain the following columns (REQUIRED unless noted optional): `time_unix_nano` (int64, REQUIRED), `observed_time_unix_nano` (int64, optional), `severity_number` (int32, optional), `severity_text` (string, optional), `body_json` (string, optional; AnyValue serialized as JSON), `service_name` (string, optional; extracted from resource attributes), `scope_name` (string, optional), `scope_version` (string, optional), `trace_id_hex` (string, optional; lowercase 32-char hex when present), `span_id_hex` (string, optional; lowercase 16-char hex when present), `flags` (int32, optional), `resource_attributes_json` (string, REQUIRED; defaults to `"{}"`), `attributes_json` (string, REQUIRED; defaults to `"{}"`). All Parquet column types SHALL be primitive in v1; native `map<string,string>` is explicitly out of scope for v1 to preserve AnyValue fidelity.

#### Scenario: Schema columns present
- **WHEN** any Parquet file produced by the handler is opened by a reader
- **THEN** all columns above are present with the specified types

#### Scenario: Resource attributes denormalized per row
- **WHEN** an OTLP request contains one ResourceLogs block with N LogRecords
- **THEN** the resulting Parquet file contains N rows
- **AND** every row has identical `resource_attributes_json` and `service_name` values

#### Scenario: Attributes are JSON strings
- **WHEN** an attribute set is `{"http.status_code": 500, "user.id": "u-42"}`
- **THEN** the corresponding Parquet column value for that row is a string containing valid JSON equivalent to the input

### Requirement: Compression configuration

The handler SHALL write Parquet files compressed with the codec named by the `CRASHLER_PARQUET_COMPRESSION` environment variable (allowed values: `GZIP`, `ZSTD`, `SNAPPY`, `BROTLI`, `LZ4`, `LZ4_RAW`, `UNCOMPRESSED`). The default SHALL be `GZIP`. If the configured codec requires a PHP extension that is not loaded, the application SHALL fail fast at boot with a clear error rather than at write time.

#### Scenario: Default GZIP compression
- **WHEN** `CRASHLER_PARQUET_COMPRESSION` is unset
- **THEN** Parquet files are written with GZIP compression

#### Scenario: ZSTD requested without ext-zstd
- **WHEN** `CRASHLER_PARQUET_COMPRESSION=ZSTD` is set and the `zstd` PHP extension is not loaded
- **THEN** the application fails to boot with an error naming the missing extension
- **AND** no requests are served

### Requirement: Memory-bounded row groups

The handler SHALL configure the Parquet writer's `Option::ROW_GROUP_SIZE_BYTES` to a value bounded such that the per-request memory footprint stays within the worker's PHP `memory_limit`. The default SHALL be 32 MiB. The handler SHALL NOT buffer the full request's row set in a single in-memory list larger than this limit.

#### Scenario: Row-group size respected
- **WHEN** a request contains records whose serialized form exceeds the row-group size
- **THEN** the writer flushes multiple row groups during the request
- **AND** the handler's peak memory usage stays bounded

### Requirement: No background workers or write-ahead log

The system SHALL NOT include a write-ahead log table, a flush worker, a console command for flushing, or any other process that runs outside of the HTTP request lifecycle in this change. All Parquet write activity SHALL occur synchronously in the request that produced the data.

#### Scenario: No flush command exists
- **WHEN** an operator lists registered Symfony console commands
- **THEN** no command for flushing, draining, or compacting Parquet files is registered

#### Scenario: No WAL table exists
- **WHEN** an operator inspects the database schema after applying all migrations from this change
- **THEN** no `log_wal` (or equivalent buffering) table is present
