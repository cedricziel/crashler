## ADDED Requirements

### Requirement: Iceberg mode opt-in

The system SHALL select between the legacy Hive-partitioned Parquet writer and the Iceberg table writer based on the `CRASHLER_TABLE_FORMAT` environment variable. Allowed values SHALL be `hive` (default) and `iceberg`. Any other value SHALL cause the application to fail to boot with a clear error naming the offending value. The selected mode SHALL apply uniformly to all signals (`logs`, `traces`, `metrics`) within a single deployment.

#### Scenario: Hive is the default
- **WHEN** `CRASHLER_TABLE_FORMAT` is unset
- **THEN** ingest writes are performed by the legacy Hive writer
- **AND** no `<APP_SHARE_DIR>/iceberg/` tree is created

#### Scenario: Iceberg mode produces tables under iceberg/
- **WHEN** `CRASHLER_TABLE_FORMAT=iceberg` is set
- **THEN** ingest writes for tenant `acme` and signal `logs` are committed to a table rooted at `<APP_SHARE_DIR>/iceberg/logs/acme/`
- **AND** the legacy Hive tree under `<APP_SHARE_DIR>/logs/acme/` is not written to

#### Scenario: Invalid mode fails fast
- **WHEN** `CRASHLER_TABLE_FORMAT=parquet-only` is set
- **THEN** the application fails to boot with an error naming the unrecognized value
- **AND** no HTTP listener is opened

### Requirement: Iceberg v2 table format

Every table written by the Iceberg writer SHALL conform to the Apache Iceberg v2 specification. `format-version` in `metadata.json` SHALL equal `2`. The writer SHALL produce only `append` snapshot operations; `delete`, `replace`, and `overwrite` operations are out of scope for this change.

#### Scenario: format-version is 2
- **WHEN** any `vN.metadata.json` produced by the writer is parsed
- **THEN** its `format-version` field equals `2`

#### Scenario: Snapshots are append-only
- **WHEN** any snapshot in any produced table is inspected
- **THEN** its `summary.operation` equals `append`

### Requirement: Per-signal-per-tenant table layout

For each `(signal, tenant)` pair, the system SHALL maintain exactly one Iceberg table rooted at `<APP_SHARE_DIR>/iceberg/<signal>/<tenant_slug>/`. The table root SHALL contain a `metadata/` directory holding all Iceberg metadata files and a `data/` directory holding the Parquet data files. Data files SHALL land at `data/date=<YYYY-MM-DD>/hour=<HH>/part-<ulid>.parquet`. `<YYYY-MM-DD>` and `<HH>` SHALL be derived from the request's wall-clock arrival time in UTC.

#### Scenario: Table root path
- **WHEN** the first request for tenant `acme` and signal `traces` is committed in Iceberg mode
- **THEN** the table root is `<APP_SHARE_DIR>/iceberg/traces/acme/`
- **AND** `<APP_SHARE_DIR>/iceberg/traces/acme/metadata/v1.metadata.json` exists
- **AND** `<APP_SHARE_DIR>/iceberg/traces/acme/data/` contains exactly one Parquet file

#### Scenario: Data file path mirrors Hive layout
- **WHEN** a request arrives at 2026-05-04 09:12 UTC for tenant `acme` and signal `logs`
- **THEN** the resulting data file path matches `<APP_SHARE_DIR>/iceberg/logs/acme/data/date=2026-05-04/hour=09/part-<ulid>.parquet`

### Requirement: Lazy table initialization

The system SHALL initialize a new Iceberg table on the first commit for a `(signal, tenant)` pair that has no existing table root. Initialization SHALL be idempotent: a second initialization call against an existing table root SHALL be a no-op and SHALL NOT bump the metadata version.

#### Scenario: First commit creates the table
- **WHEN** the writer commits its first snapshot for a tenant that has no existing Iceberg table
- **THEN** `metadata/v1.metadata.json` and `metadata/version-hint.text` are created before the snapshot is appended
- **AND** the resulting commit produces `v2.metadata.json` (initial empty table at v1, append at v2)

#### Scenario: Re-initialization is a no-op
- **WHEN** initialization is invoked against a table whose `version-hint.text` contains `5`
- **THEN** `version-hint.text` still contains `5` after the call
- **AND** no new metadata file is written

### Requirement: Schema and partition spec from the catalog

Each Iceberg table's schema SHALL be derived from the `config/schemas/<signal>/v<n>.yaml` schema-catalog entry. Field IDs SHALL be assigned in catalog declaration order starting at `1`. The initial partition spec SHALL contain identity transforms on a `date` column (string) and an `hour` column (string), both derived from the request's ingest time and written into every row of the data file.

#### Scenario: Schema mirrors the catalog
- **WHEN** the `logs/v1` catalog entry declares N columns
- **THEN** the table's `schemas[0].fields` array contains N + 2 entries (the documented columns plus the universal `_schema_version` and `_schema_id` columns appended by the writer)
- **AND** every field carries a stable `field-id` matching its catalog declaration order

#### Scenario: Partition spec is identity(date, hour)
- **WHEN** any table's `partition-specs[0]` is inspected
- **THEN** it contains exactly two fields, both with `transform: 'identity'`
- **AND** the first field's `name` is `date` and the second's `name` is `hour`

### Requirement: Commit protocol with version-hint pointer and locking

Every Iceberg commit SHALL follow this protocol:
1. Acquire an exclusive `flock` on `<table-root>/metadata/.commit.lock` within `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS` milliseconds.
2. Read `metadata/version-hint.text` to determine the current version `N`.
3. Load `metadata/vN.metadata.json` into memory.
4. Write the new manifest file as `<ulid>.avro.tmp`, fsync, rename to `<ulid>.avro`.
5. Write the new manifest list as `snap-<snapshot-id>-<ulid>.avro.tmp`, fsync, rename.
6. Write `v(N+1).metadata.json.tmp`, fsync, rename to `v(N+1).metadata.json`.
7. Write `version-hint.text.tmp` containing `N+1`, fsync, rename over `version-hint.text`.
8. Release the lock.

If `flock` cannot be acquired within the timeout, the commit SHALL be retried (with a fresh re-read of `version-hint.text`) up to `CRASHLER_ICEBERG_COMMIT_RETRIES` times before failing the request with 5xx. If any step from 4 onward fails, the system SHALL unlink any `.tmp` and finalized files produced by this commit attempt before bubbling the error.

#### Scenario: Successful commit advances version-hint
- **WHEN** a commit completes against a table whose `version-hint.text` contained `7`
- **THEN** `version-hint.text` contains `8`
- **AND** `v8.metadata.json` exists with the new snapshot appended
- **AND** `v7.metadata.json` is unchanged

#### Scenario: Lock contention retries
- **WHEN** two requests race on the same `(signal, tenant)` table
- **THEN** both requests succeed
- **AND** the resulting `version-hint.text` advances by exactly two
- **AND** both snapshots appear in the final `metadata.json`'s `snapshots` array in commit order

#### Scenario: Lock timeout fails the request
- **WHEN** `flock` cannot be acquired within `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS` after `CRASHLER_ICEBERG_COMMIT_RETRIES` retries
- **THEN** the request returns 5xx with a clear error
- **AND** no partial metadata or manifest files remain in the table root

#### Scenario: Mid-commit failure leaves no orphans
- **WHEN** the manifest-list write succeeds but the `v(N+1).metadata.json` rename fails (e.g., disk full)
- **THEN** the lock is released
- **AND** the orphan manifest and manifest-list files are unlinked before the request returns 5xx
- **AND** `version-hint.text` is unchanged at `N`

### Requirement: One snapshot per accepted request

The Iceberg writer SHALL produce exactly one new snapshot per accepted OTLP request. The snapshot SHALL reference exactly one new manifest, which SHALL contain exactly one new data file (the Parquet file produced for that request). The manifest list SHALL include the new manifest and carry forward the manifest entries from the previous snapshot.

#### Scenario: One request, one snapshot, one data file
- **WHEN** a single OTLP request containing 100 LogRecords is accepted
- **THEN** the resulting commit produces exactly one new snapshot
- **AND** the snapshot's manifest list references exactly one new manifest
- **AND** that manifest references exactly one data file with `record_count = 100`

#### Scenario: Snapshot history is preserved
- **WHEN** ten consecutive requests have been committed against a table
- **THEN** `metadata.json`'s `snapshots` array contains all ten snapshot entries
- **AND** `current-snapshot-id` matches the tenth snapshot's id
- **AND** `snapshot-log` lists all ten timestamps in commit order

### Requirement: Manifest data-file metadata

Each manifest entry SHALL populate, at minimum: `data_file.file_path` (table-root-relative), `data_file.file_format = 'PARQUET'`, `data_file.partition` (with the `date` and `hour` values from the request), `data_file.record_count`, and `data_file.file_size_in_bytes`. Lower and upper bounds for the `time_unix_nano` column SHALL be populated from the row group statistics produced by the Parquet writer.

#### Scenario: Manifest entry includes record count and size
- **WHEN** a manifest produced by the writer is read by PyIceberg
- **THEN** every `data_file` entry has a non-null `record_count` matching the actual Parquet row count
- **AND** every entry has a non-null `file_size_in_bytes` matching the on-disk file size

#### Scenario: Time bounds populated
- **WHEN** a data file's records have `time_unix_nano` values in `[t_min, t_max]`
- **THEN** the manifest entry's `lower_bounds` for the `time_unix_nano` column equals `t_min`
- **AND** the entry's `upper_bounds` equals `t_max`

### Requirement: DuckDB read compatibility

Tables produced by the Iceberg writer SHALL be readable by DuckDB ≥ 0.10 with the `iceberg` extension installed, via `iceberg_scan('<table-root>')`. The smoke test described in the README SHALL pass against a freshly written table.

#### Scenario: DuckDB reads back the row count
- **WHEN** `K` requests have been committed in Iceberg mode against a single tenant table, with each request containing `r_i` records (1 ≤ i ≤ K)
- **THEN** `SELECT count(*) FROM iceberg_scan('<table-root>')` returns `Σ r_i`

#### Scenario: DuckDB reads back partition-pruned queries
- **WHEN** the table contains data files for both `date=2026-05-03` and `date=2026-05-04`
- **THEN** `SELECT count(*) FROM iceberg_scan('<table-root>') WHERE date = '2026-05-04'` returns only the `2026-05-04` row count
- **AND** DuckDB's query plan reports manifest-level pruning of the `2026-05-03` files

### Requirement: No background workers or external catalog

The Iceberg writer SHALL NOT include a background worker, scheduled compaction job, snapshot-expiry job, REST/Hive/Glue catalog client, or any process running outside of the HTTP request lifecycle. All Iceberg metadata writes SHALL happen synchronously within the request that produced the data.

#### Scenario: No new console commands
- **WHEN** an operator lists registered Symfony console commands after applying this change
- **THEN** no new commands related to Iceberg, snapshot expiry, manifest rewrite, or compaction are registered

#### Scenario: No new long-running processes
- **WHEN** an operator inspects the deployed process tree
- **THEN** the only processes related to ingest are PHP-FPM workers and the web server
- **AND** no Iceberg-specific daemon is present

### Requirement: Single-host POSIX filesystem assumption

The Iceberg writer SHALL assume a local POSIX filesystem with working `flock(2)`, `rename(2)` atomicity, and `fsync(2)`. The system SHALL NOT support object-storage backends (S3, GCS, Azure Blob), network filesystems with broken `flock` semantics (NFS without lockd), or multi-host writers in this change. The README SHALL document this requirement.

#### Scenario: Documented requirement
- **WHEN** an operator reads the README's Iceberg section
- **THEN** the requirement for local POSIX storage and the single-writer-host assumption is documented

### Requirement: Operator hand-edit hazard documented

The README SHALL document that direct modification of files inside an Iceberg table tree (deleting a Parquet file, hand-editing a `metadata.json`, removing a manifest) will leave the table in an inconsistent state and that Crashler does not detect or repair such tampering.

#### Scenario: Hazard documented
- **WHEN** an operator reads the README's Iceberg operations section
- **THEN** the hand-edit hazard is called out explicitly with the recommended remediation (restore from the most recent intact `vN.metadata.json` and matching manifest set)
