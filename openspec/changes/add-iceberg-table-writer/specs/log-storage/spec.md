## REMOVED Requirements

### Requirement: On-disk layout

**Reason**: This change replaces the Hive-partitioned, one-file-per-request layout with Iceberg tables. The new on-disk layout (`<APP_SHARE_DIR>/iceberg/logs/<tenant>/{metadata,data}/…`) is specified by the `iceberg-storage` capability.

### Requirement: Atomic file commit via .tmp + rename

**Reason**: Atomic commit semantics now belong to `iceberg-storage` and apply to manifest, manifest-list, and `metadata.json` files in addition to data files. The per-request rename-to-final-path contract is gone: requests rename data files to final paths, but **visibility** to readers requires a snapshot commit.

### Requirement: No background workers or write-ahead log

**Reason**: Replaced by `iceberg-storage`'s "No background long-running workers in M1" requirement, which is more permissive (it allows the optional cron flush and the once-on-deploy recovery worker) and signal-agnostic.

## MODIFIED Requirements

### Requirement: Storage root configuration

The system SHALL resolve its storage root from the `APP_SHARE_DIR` environment variable. The application SHALL fail fast at boot if `APP_SHARE_DIR` is unset, refers to a non-existent directory, or is not writable by the web-server process. Iceberg tables for the `logs` signal SHALL be rooted under `<APP_SHARE_DIR>/iceberg/logs/<tenant_slug>/` (per the `iceberg-storage` capability). The legacy `<APP_SHARE_DIR>/logs/<tenant_slug>/date=…/` tree SHALL NOT be created or written to.

#### Scenario: Missing APP_SHARE_DIR fails fast
- **WHEN** the application boots without `APP_SHARE_DIR` set
- **THEN** boot fails with a clear error before any HTTP listener is opened

#### Scenario: Unwritable storage root fails fast
- **WHEN** `APP_SHARE_DIR` points to a directory the worker user cannot write to
- **THEN** boot fails with a clear error

#### Scenario: Iceberg root used for logs
- **WHEN** the first request for tenant `acme` is accepted
- **THEN** the Iceberg table is rooted at `<APP_SHARE_DIR>/iceberg/logs/acme/`
- **AND** no file is created under `<APP_SHARE_DIR>/logs/acme/`

### Requirement: Parquet schema and types

Each data file written for the logs signal SHALL use the column layout defined by the `logs/v1` schema in the schema catalog (`config/schemas/logs/v1.yaml`), unchanged from prior behavior. The full row shape, the universal `_schema_version` and `_schema_id` columns, and the shadow-promotion semantics are unchanged. The schema is now also expressed as an Iceberg `Schema` value object inside `metadata.json`, with `field-id`s assigned in catalog declaration order; the on-disk Parquet files themselves do not change shape.

#### Scenario: Schema columns present in Parquet data files
- **WHEN** any Parquet data file produced for the logs signal is opened by a reader
- **THEN** all columns documented in `logs/v1` are present with the documented types and repetitions
- **AND** the file's row schema also exposes `_schema_version` (int32 REQUIRED) and `_schema_id` (string REQUIRED)

#### Scenario: Iceberg schema mirrors Parquet schema
- **WHEN** an Iceberg `metadata.json` for a logs table is parsed
- **THEN** its `schemas[0].fields` array contains every column from `logs/v1` plus the two universal columns
- **AND** every field carries a stable `field-id`

### Requirement: Compression configuration

Each data file SHALL be written using the codec named by the `CRASHLER_PARQUET_COMPRESSION` environment variable (allowed values: `GZIP`, `ZSTD`, `SNAPPY`, `BROTLI`, `LZ4`, `LZ4_RAW`, `UNCOMPRESSED`). The default SHALL remain `GZIP`. If the configured codec requires a PHP extension that is not loaded, the application SHALL fail fast at boot. (Codec selection now flows through `App\Iceberg\Parquet\ParquetWriter`; the Crashler adapter wires the env var to its constructor argument.)

#### Scenario: Default GZIP compression
- **WHEN** `CRASHLER_PARQUET_COMPRESSION` is unset
- **THEN** Parquet data files are written with GZIP compression

#### Scenario: ZSTD requested without ext-zstd
- **WHEN** `CRASHLER_PARQUET_COMPRESSION=ZSTD` is set and the `zstd` PHP extension is not loaded
- **THEN** the application fails to boot with an error naming the missing extension

### Requirement: Memory-bounded row groups

The Parquet writer SHALL configure `Option::ROW_GROUP_SIZE_BYTES` such that the per-request memory footprint stays within the worker's PHP `memory_limit`. The default SHALL be 32 MiB. The handler SHALL NOT buffer the full request's row set in a single in-memory list larger than this limit. Unchanged from prior behavior; codec/row-group config now flows through the Iceberg adapter.

#### Scenario: Row-group size respected
- **WHEN** a request contains records whose serialized form exceeds the row-group size
- **THEN** the writer flushes multiple row groups during the request
- **AND** the handler's peak memory usage stays bounded

## ADDED Requirements

### Requirement: Storage delegated to iceberg-storage

All on-disk structure, atomic commit semantics, snapshot management, batched commits, visibility contract, and catalog operations for the logs signal SHALL be governed by the `iceberg-storage` capability. This capability SHALL only describe Parquet column shape, compression configuration, and row-group memory bounds — i.e. what goes *inside* a logs data file. Where this capability and `iceberg-storage` overlap, `iceberg-storage` SHALL be authoritative.

#### Scenario: log-storage governs Parquet content; iceberg-storage governs the table
- **WHEN** an operator inspects an Iceberg-committed snapshot for the logs signal
- **THEN** the data file's Parquet schema, codec, and row-group size are governed by `log-storage`
- **AND** the surrounding manifest, manifest list, `metadata.json`, partition spec, snapshot history, and commit lock are governed by `iceberg-storage`
