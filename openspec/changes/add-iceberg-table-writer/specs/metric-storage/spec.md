## REMOVED Requirements

### Requirement: On-disk layout

**Reason**: Replaced by `iceberg-storage`'s table-rooted layout (`<APP_SHARE_DIR>/iceberg/metrics/<tenant>/{metadata,data}/…`).

### Requirement: Atomic file commit via .tmp + rename

**Reason**: Atomic commit semantics now belong to `iceberg-storage`. Visibility to readers requires a snapshot commit.

### Requirement: No background workers or write-ahead log for metrics

**Reason**: Replaced by `iceberg-storage`'s signal-agnostic "No background long-running workers in M1" requirement.

## MODIFIED Requirements

### Requirement: Storage root and signal subdirectory

Iceberg tables for the `metrics` signal SHALL be rooted under `<APP_SHARE_DIR>/iceberg/metrics/<tenant_slug>/` (per the `iceberg-storage` capability). The legacy `<APP_SHARE_DIR>/metrics/<tenant_slug>/date=…/` tree SHALL NOT be created or written to.

#### Scenario: Iceberg root used for metrics
- **WHEN** the first metrics request for tenant `acme` is accepted
- **THEN** the Iceberg table is rooted at `<APP_SHARE_DIR>/iceberg/metrics/acme/`
- **AND** no file is created under `<APP_SHARE_DIR>/metrics/acme/`

### Requirement: Parquet schema and types

Each data file written for the metrics signal SHALL use the column layout defined by the `metrics/v1` schema in the schema catalog (`config/schemas/metrics/v1.yaml`), unchanged from prior behavior — including the `metric_type` discriminator and the JSON-string columns for histogram buckets, exponential-histogram detail, summary quantiles, and exemplars. The schema is also expressed as an Iceberg `Schema` value object with stable `field-id`s.

#### Scenario: Schema columns present in metric data files
- **WHEN** any data file produced for the metrics signal is opened
- **THEN** all columns documented in `metrics/v1` are present with the documented types
- **AND** the `metric_type` discriminator column is present

### Requirement: Compression configuration

Codec configuration is unchanged: `CRASHLER_PARQUET_COMPRESSION` selects the codec, default `GZIP`, fail-fast on missing extension. Codec selection flows through `App\Iceberg\Parquet\ParquetWriter`.

#### Scenario: Default GZIP compression
- **WHEN** `CRASHLER_PARQUET_COMPRESSION` is unset
- **THEN** metric Parquet data files are written with GZIP compression

### Requirement: Memory-bounded row groups

`Option::ROW_GROUP_SIZE_BYTES` defaults to 32 MiB, unchanged.

#### Scenario: Row-group size respected
- **WHEN** a metrics request contains data points whose serialized form exceeds the row-group size
- **THEN** the writer flushes multiple row groups during the request

## ADDED Requirements

### Requirement: Storage delegated to iceberg-storage

All on-disk structure, atomic commit semantics, snapshot management, batched commits, visibility contract, and catalog operations for the metrics signal SHALL be governed by the `iceberg-storage` capability. This capability SHALL only describe Parquet column shape, compression configuration, and row-group memory bounds. Where this capability and `iceberg-storage` overlap, `iceberg-storage` SHALL be authoritative.

#### Scenario: metric-storage governs content; iceberg-storage governs the table
- **WHEN** an operator inspects an Iceberg-committed snapshot for the metrics signal
- **THEN** the data file's Parquet schema, codec, and row-group size are governed by `metric-storage`
- **AND** the surrounding Iceberg metadata is governed by `iceberg-storage`
