## REMOVED Requirements

### Requirement: On-disk layout

**Reason**: Replaced by `iceberg-storage`'s table-rooted layout (`<APP_SHARE_DIR>/iceberg/traces/<tenant>/{metadata,data}/…`).

### Requirement: Atomic file commit via .tmp + rename

**Reason**: Atomic commit semantics now belong to `iceberg-storage` and apply to manifest/manifest-list/`metadata.json` files in addition to data files. Visibility to readers requires a snapshot commit, no longer a single rename.

### Requirement: No background workers or write-ahead log for traces

**Reason**: Replaced by `iceberg-storage`'s "No background long-running workers in M1" requirement, which is signal-agnostic.

## MODIFIED Requirements

### Requirement: Storage root and signal subdirectory

The system SHALL resolve its storage root from the `APP_SHARE_DIR` environment variable. Iceberg tables for the `traces` signal SHALL be rooted under `<APP_SHARE_DIR>/iceberg/traces/<tenant_slug>/` (per the `iceberg-storage` capability). The legacy `<APP_SHARE_DIR>/traces/<tenant_slug>/date=…/` tree SHALL NOT be created or written to.

#### Scenario: Iceberg root used for traces
- **WHEN** the first trace request for tenant `acme` is accepted
- **THEN** the Iceberg table is rooted at `<APP_SHARE_DIR>/iceberg/traces/acme/`
- **AND** no file is created under `<APP_SHARE_DIR>/traces/acme/`

### Requirement: Parquet schema and types

Each data file written for the traces signal SHALL use the column layout defined by the `traces/v1` schema in the schema catalog (`config/schemas/traces/v1.yaml`), unchanged from prior behavior. The schema is also expressed as an Iceberg `Schema` value object inside `metadata.json`, with `field-id`s assigned in catalog declaration order.

#### Scenario: Schema columns present in trace data files
- **WHEN** any data file produced for the traces signal is opened
- **THEN** all columns documented in `traces/v1` are present with the documented types

### Requirement: Compression configuration

Codec configuration is unchanged: `CRASHLER_PARQUET_COMPRESSION` selects the codec, default `GZIP`, fail-fast on missing extension. Codec selection flows through `App\Iceberg\Parquet\ParquetWriter`.

#### Scenario: Default GZIP compression
- **WHEN** `CRASHLER_PARQUET_COMPRESSION` is unset
- **THEN** trace Parquet data files are written with GZIP compression

### Requirement: Memory-bounded row groups

`Option::ROW_GROUP_SIZE_BYTES` defaults to 32 MiB, unchanged from prior behavior; routed through the Iceberg adapter.

#### Scenario: Row-group size respected
- **WHEN** a trace request contains records whose serialized form exceeds the row-group size
- **THEN** the writer flushes multiple row groups during the request

## ADDED Requirements

### Requirement: Storage delegated to iceberg-storage

All on-disk structure, atomic commit semantics, snapshot management, batched commits, visibility contract, and catalog operations for the traces signal SHALL be governed by the `iceberg-storage` capability. This capability SHALL only describe Parquet column shape, compression configuration, and row-group memory bounds. Where this capability and `iceberg-storage` overlap, `iceberg-storage` SHALL be authoritative.

#### Scenario: trace-storage governs content; iceberg-storage governs the table
- **WHEN** an operator inspects an Iceberg-committed snapshot for the traces signal
- **THEN** the data file's Parquet schema, codec, and row-group size are governed by `trace-storage`
- **AND** the surrounding Iceberg metadata is governed by `iceberg-storage`
