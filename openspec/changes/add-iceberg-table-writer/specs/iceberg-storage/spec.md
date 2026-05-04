## ADDED Requirements

### Requirement: Iceberg is the only on-disk format

The system SHALL write all signal data (logs, traces, metrics) as Apache Iceberg v2 tables. There SHALL NOT be a fallback or coexistence path with the legacy Hive-partitioned Parquet writer; the Hive writer SHALL be removed in this change. Existing on-disk Hive trees SHALL be left untouched (operators may keep them for archival reads via DuckDB) but Crashler SHALL NOT read from or write to them.

#### Scenario: Iceberg is the only writer
- **WHEN** any accepted OTLP request is processed for any signal
- **THEN** the produced data file lands under `<APP_SHARE_DIR>/iceberg/<signal>/<tenant_slug>/data/…`
- **AND** no file is created under the legacy `<APP_SHARE_DIR>/<signal>/<tenant_slug>/date=…/hour=…/` tree

#### Scenario: No format switch exists
- **WHEN** an operator inspects the deployed configuration
- **THEN** there is no `CRASHLER_TABLE_FORMAT` environment variable
- **AND** there is no Symfony parameter or config key that selects between Hive and Iceberg

### Requirement: Iceberg v2 wire format with v3-shaped value objects

Every `metadata.json` produced by the writer SHALL declare `format-version: 2`. The library's value objects SHALL accommodate v3 fields (`refs`, `statistics`, `partition-statistics`, `last-partition-id`, `default-sort-order-id`) as nullable so that M9 can populate them without changing public types.

#### Scenario: format-version is 2
- **WHEN** any `vN.metadata.json` produced by the writer is parsed
- **THEN** its `format-version` field equals `2`

#### Scenario: v3-shaped fields are accommodated
- **WHEN** a `TableMetadata` value object is constructed by M1 code
- **THEN** the `refs`, `statistics`, `partition-statistics`, `last-partition-id`, `default-sort-order-id` properties are accessible and `null`-valued

### Requirement: Per-signal-per-tenant table layout

For each `(signal, tenant)` pair, the system SHALL maintain exactly one Iceberg table rooted at `<APP_SHARE_DIR>/iceberg/<signal>/<tenant_slug>/`. The table root SHALL contain a `metadata/` directory holding all Iceberg metadata files, a `data/` directory holding committed data files, and a `delete-data/` directory holding position and equality delete files (when produced via `RowDelta` / `OverwriteFiles`). Data file paths SHALL match `data/date=<YYYY-MM-DD>/hour=<HH>/part-<ulid>.parquet`. Date and hour SHALL be derived from the request's wall-clock arrival time in UTC.

#### Scenario: Table root path
- **WHEN** the first request for tenant `acme` and signal `traces` lands
- **THEN** the table is rooted at `<APP_SHARE_DIR>/iceberg/traces/acme/`
- **AND** `<APP_SHARE_DIR>/iceberg/traces/acme/metadata/v1.metadata.json` exists
- **AND** `<APP_SHARE_DIR>/iceberg/traces/acme/data/` is created

#### Scenario: Data file path mirrors Hive layout
- **WHEN** a request arrives at 2026-05-04 09:12 UTC for tenant `acme` and signal `logs`
- **THEN** the data file path matches `<APP_SHARE_DIR>/iceberg/logs/acme/data/date=2026-05-04/hour=09/part-<ulid>.parquet`

### Requirement: Lazy table initialization

The system SHALL initialize a new Iceberg table on the first commit for a `(signal, tenant)` pair that has no existing table root. Initialization SHALL be idempotent and race-safe.

#### Scenario: First request creates the table
- **WHEN** the first ingest request for a tenant lands
- **THEN** `metadata/v1.metadata.json` and `metadata/version-hint.text` are created before the data file is written
- **AND** subsequent requests load the existing table without re-initializing

#### Scenario: Concurrent first requests do not duplicate
- **WHEN** two requests for the same brand-new tenant arrive simultaneously
- **THEN** the table is initialized exactly once
- **AND** both data files are recorded as pending against the same table

### Requirement: Schema and partition spec from the catalog

Each Iceberg table's schema SHALL be derived from the `config/schemas/<signal>/v<n>.yaml` schema-catalog entry. Field IDs SHALL be assigned in catalog declaration order starting at `1`. The initial partition spec SHALL contain identity transforms on a `date` column (string) and an `hour` column (string), both derived from the request's ingest time. The library SHALL accommodate the full v2 transform set (`identity`, `bucket`, `truncate`, `year`, `month`, `day`, `hour`) in its value objects, but only `identity` SHALL be constructible from public factories in M1; other transform factories SHALL throw `UnsupportedOperationException`.

#### Scenario: Schema mirrors the catalog
- **WHEN** the `logs/v1` catalog entry declares N columns
- **THEN** the table's `schemas[0].fields` array contains N + 2 entries (the documented columns plus the universal `_schema_version` and `_schema_id` columns)
- **AND** every field carries a stable `field-id` matching its catalog declaration order

#### Scenario: Partition spec is identity(date, hour)
- **WHEN** any table's `partition-specs[0]` is inspected
- **THEN** it contains exactly two fields, both with `transform: 'identity'`
- **AND** the first field's `name` is `date` and the second's is `hour`

#### Scenario: Non-identity transforms are gated
- **WHEN** application code calls `Transform::bucket(8)`, `Transform::truncate(16)`, `Transform::year()`, `Transform::month()`, `Transform::day()`, or `Transform::hour()` in M1
- **THEN** the call throws `UnsupportedOperationException`

### Requirement: Batched commit protocol

The system SHALL commit accumulated data files in batches rather than one snapshot per request. Per-request work SHALL: (a) write the data file durably, (b) record an entry in the table's pending-index, and (c) optionally trigger a flush if any threshold is crossed. Thresholds SHALL be: file count ≥ `CRASHLER_ICEBERG_BATCH_MAX_FILES`, accumulated pending bytes ≥ `CRASHLER_ICEBERG_BATCH_MAX_BYTES`, or oldest-pending-entry age ≥ `CRASHLER_ICEBERG_BATCH_MAX_AGE_MS`. A flush SHALL coalesce all pending entries into a single Iceberg `append` snapshot. The system SHALL also expose a `crashler:iceberg:flush` console command for operator-driven flushes and SHALL run a recovery flush at boot for any non-empty pending-index.

#### Scenario: Per-request work is small and durable
- **WHEN** any single OTLP request is accepted in isolation
- **THEN** a Parquet data file is fsync'd and renamed to its final path under `data/`
- **AND** an entry is appended to `metadata/.pending-index.json`
- **AND** no manifest, manifest list, or `metadata.json` is written
- **AND** the response is `200 OK`

#### Scenario: File-count threshold triggers a flush
- **WHEN** `CRASHLER_ICEBERG_BATCH_MAX_FILES` is `32` and the 32nd request crosses the threshold
- **THEN** that request also performs a commit
- **AND** the resulting snapshot's manifest references all 32 data files
- **AND** the pending-index is truncated

#### Scenario: Byte-count threshold triggers a flush
- **WHEN** `CRASHLER_ICEBERG_BATCH_MAX_BYTES` is `64 MiB` and the accumulated pending size exceeds 64 MiB
- **THEN** the next request performs a commit covering all pending entries

#### Scenario: Age threshold triggers a flush
- **WHEN** the oldest pending entry's age exceeds `CRASHLER_ICEBERG_BATCH_MAX_AGE_MS` (default 5 s)
- **THEN** the next request to arrive for that table performs a commit covering all pending entries

#### Scenario: Manual flush command commits pending entries
- **WHEN** an operator runs `bin/console crashler:iceberg:flush --signal=logs --tenant=acme`
- **THEN** any pending entries for that table are coalesced into a snapshot
- **AND** the command exits 0 even if there were no pending entries

#### Scenario: Recovery flush at boot
- **WHEN** the application boots and any table's `metadata/.pending-index.json` is non-empty
- **THEN** a recovery flush is enqueued for that table
- **AND** kernel boot is not blocked on the flush completing

### Requirement: Visibility contract — durable on response, snapshot-visible on flush

The system's HTTP `200 OK` response SHALL mean: the data file is fsync'd on disk and recorded in the pending-index. It SHALL NOT mean: the rows are visible to readers. Visibility in DuckDB / PyIceberg SHALL follow the next batched commit. The upper bound on visibility delay SHALL be `CRASHLER_ICEBERG_BATCH_MAX_AGE_MS` plus typical commit latency. The README SHALL document this contract explicitly as a deliberate change from the prior Hive writer's "200 = visible" semantics.

#### Scenario: 200 means durable
- **WHEN** a request returns `200 OK`
- **THEN** the corresponding data file exists at its final path on disk
- **AND** the file has been fsync'd

#### Scenario: Visibility follows the next commit
- **WHEN** a tenant's pending-index has 1 entry and no threshold is crossed
- **THEN** that row is not visible to a DuckDB `iceberg_scan` query
- **AND** after the next flush (request-driven, cron, or manual), the row becomes visible

#### Scenario: Documented contract
- **WHEN** an operator reads the README's "Visibility" section
- **THEN** the durable-on-200 / visible-on-commit semantics are spelled out
- **AND** the relationship between `BATCH_MAX_AGE_MS` and worst-case visibility delay is documented

### Requirement: Catalog commit primitive

The `Catalog::commit(TableMetadata previous, TableMetadata next)` operation SHALL: (1) acquire `flock(LOCK_EX)` on `<table-root>/metadata/.commit.lock` within `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS`; (2) verify that the on-disk current `version-hint.text` matches `previous.last-sequence-number`'s underlying version; (3) write the new manifest, manifest list, delete-manifest, `v(N+1).metadata.json`, and `version-hint.text` files atomically (`.tmp` + fsync + rename); (4) release the lock. If the version check fails, the operation SHALL throw `CommitFailedException` so the caller can rebase. If `flock` cannot be acquired within the timeout, callers SHALL retry up to `CRASHLER_ICEBERG_COMMIT_RETRIES` times before failing the request with 5xx. Mid-commit failures SHALL unlink any `.tmp` and finalized files produced by the failed attempt.

#### Scenario: Successful commit advances version-hint
- **WHEN** a commit completes against a table whose `version-hint.text` contained `7`
- **THEN** `version-hint.text` contains `8`
- **AND** `v8.metadata.json` exists with the new snapshot appended
- **AND** `v7.metadata.json` is unchanged

#### Scenario: Lock contention retries
- **WHEN** two flushers race on the same table
- **THEN** both eventually succeed
- **AND** `version-hint.text` advances by exactly two
- **AND** both snapshots appear in the final `metadata.json`'s `snapshots` array in commit order

#### Scenario: Lock timeout fails the request
- **WHEN** `flock` cannot be acquired within `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS` after `CRASHLER_ICEBERG_COMMIT_RETRIES` retries
- **THEN** the request returns 5xx with a clear error
- **AND** no partial metadata or manifest files remain

#### Scenario: Mid-commit failure leaves no orphans
- **WHEN** the manifest-list write succeeds but the `v(N+1).metadata.json` rename fails
- **THEN** the lock is released
- **AND** the orphan manifest and manifest-list files are unlinked
- **AND** `version-hint.text` is unchanged at `N`

### Requirement: Full v2 write API surface

The library SHALL expose three write builders in M1: `AppendFiles`, `OverwriteFiles`, and `RowDelta`. `AppendFiles` SHALL produce `summary.operation = 'append'` snapshots. `RowDelta` SHALL produce `'overwrite'` snapshots that combine new data files with positional and/or equality delete files. `OverwriteFiles` SHALL produce `'overwrite'` snapshots that supersede prior data files matching a row filter. The library SHALL also expose `PositionDeleteWriter` and `EqualityDeleteWriter` that emit Iceberg-format Parquet delete files conformant with the v2 spec. Crashler's OTLP ingest path SHALL exercise only `AppendFiles` in M1; the other builders SHALL be available for library consumers and future Crashler features.

#### Scenario: AppendFiles produces an append snapshot
- **WHEN** `Table::newAppend()->appendFiles([$df1, $df2, $df3])->commit()` is called
- **THEN** the resulting snapshot has `summary.operation = 'append'`
- **AND** its manifest references all three data files

#### Scenario: RowDelta produces an overwrite snapshot with deletes
- **WHEN** `Table::newRowDelta()->addRows($df)->addDeletes($pdf)->addDeletes($edf)->commit()` is called with one data file, one position-delete file, one equality-delete file
- **THEN** the resulting snapshot has `summary.operation = 'overwrite'`
- **AND** the data manifest references the data file
- **AND** a separate delete manifest references both delete files
- **AND** each delete file's manifest entry has the correct `content` value (`position-deletes` or `equality-deletes`)

#### Scenario: PositionDeleteWriter emits the documented schema
- **WHEN** `PositionDeleteWriter::write([(string $path, int $pos), …], $outputFile)` is called
- **THEN** the resulting Parquet file has columns `file_path: string` (required) and `pos: long` (required)
- **AND** PyIceberg reads back the same rows

#### Scenario: EqualityDeleteWriter emits the configured columns
- **WHEN** `EqualityDeleteWriter::write($rows, [$fieldId1, $fieldId2], $outputFile)` is called
- **THEN** the resulting Parquet file's schema contains exactly the equality columns
- **AND** PyIceberg reads back the same rows
- **AND** the manifest entry for that file has `equality_ids = [$fieldId1, $fieldId2]`

### Requirement: Manifest data-file metadata

Each manifest entry SHALL populate, at minimum: `data_file.file_path` (relative to the table location), `data_file.file_format = 'PARQUET'`, `data_file.partition` (with the `date` and `hour` values), `data_file.record_count`, `data_file.file_size_in_bytes`, `data_file.spec_id`, and `data_file.content` (`data | position-deletes | equality-deletes`). Lower and upper bounds for the `time_unix_nano` column SHALL be populated from the row group statistics produced by the Parquet writer. Other v2 stat fields (`column_sizes`, `value_counts`, `null_value_counts`, `key_metadata`, `split_offsets`) SHALL be populated when the Parquet writer surfaces them and `null` otherwise.

#### Scenario: Manifest entry includes record count and size
- **WHEN** a manifest produced by the writer is read by PyIceberg
- **THEN** every `data_file` entry has a non-null `record_count` matching the actual Parquet row count
- **AND** every entry has a non-null `file_size_in_bytes` matching the on-disk file size
- **AND** every entry has a non-null `content` value

#### Scenario: Time bounds populated
- **WHEN** a data file's records have `time_unix_nano` values in `[t_min, t_max]`
- **THEN** the manifest entry's `lower_bounds` for the `time_unix_nano` column equals `t_min`
- **AND** the entry's `upper_bounds` equals `t_max`

### Requirement: DuckDB read compatibility

Tables produced by the writer SHALL be readable by DuckDB ≥ 0.10 with the `iceberg` extension installed, via `iceberg_scan('<table-root>')`. Tables containing position-delete or equality-delete files (produced via `RowDelta` or `OverwriteFiles`) SHALL also be readable by DuckDB with the deleted rows correctly excluded.

#### Scenario: DuckDB reads back the row count
- **WHEN** `K` requests have been committed (across one or more snapshots) for a single tenant table, with each request containing `r_i` records
- **THEN** `SELECT count(*) FROM iceberg_scan('<table-root>')` returns `Σ r_i`

#### Scenario: DuckDB reads back partition-pruned queries
- **WHEN** the table contains data files for both `date=2026-05-03` and `date=2026-05-04`
- **THEN** `SELECT count(*) FROM iceberg_scan('<table-root>') WHERE date = '2026-05-04'` returns only the `2026-05-04` row count
- **AND** DuckDB's query plan reports manifest-level pruning of the `2026-05-03` files

#### Scenario: DuckDB applies position deletes
- **WHEN** the table has been written via `RowDelta` with one data file containing 100 rows and one position-delete file referencing 5 rows from that data file
- **THEN** `SELECT count(*) FROM iceberg_scan('<table-root>')` returns `95`

### Requirement: Library extraction-ready namespace boundary

The Iceberg library code SHALL live entirely within the `App\Iceberg\*` namespace. No file under `src/Iceberg/` SHALL `use` any class outside that namespace, with three permitted exceptions: PHP standard library and PSR interfaces (`Psr\Log\LoggerInterface`, and from M7 onward `Psr\Http\Client\ClientInterface` plus PSR-17 factories); the Avro dependency, only inside `App\Iceberg\Avro\*`; the Parquet dependency, only inside `App\Iceberg\Parquet\*`. The `App\Iceberg\*` code SHALL NOT import anything from `Symfony\*`, `Doctrine\*`, or any other Crashler namespace, and SHALL NOT call `getenv()` or read configuration from any global. Crashler-specific glue lives under `App\Storage\Iceberg\*` (the adapter), which is the only place that bridges the two worlds. CI SHALL enforce the boundary with a static check that fails the build on violation.

#### Scenario: No Symfony imports in the public namespace
- **WHEN** the `composer iceberg:lint-boundary` script is run
- **THEN** zero violations are reported
- **AND** the script exits 0

#### Scenario: Adapter is the only Crashler-aware bridge
- **WHEN** an operator searches for `App\Iceberg\` imports across the Crashler codebase
- **THEN** they appear only inside `src/Storage/Iceberg/*` (the adapter) and inside `src/Iceberg/*` itself

#### Scenario: Configuration enters via constructor
- **WHEN** any class in `App\Iceberg\*` is instantiated
- **THEN** all configuration (catalog, FileIO, retry counts, batch thresholds, lock timeouts) arrives as constructor arguments
- **AND** no class in `App\Iceberg\*` calls `getenv` or reads from a Symfony parameter bag

### Requirement: Read-side stubs for forward-compat

The library SHALL declare interface signatures for the read surface (`Table::newScan`, `Manifest\ManifestReader`, `Manifest\ManifestListReader`, `Parquet\ParquetReader`, `Expression\Expression`, `Expression\Expressions`) so that the M2 reader change does not introduce new public types. Concrete read implementations SHALL throw `UnsupportedOperationException` in M1.

#### Scenario: Read interfaces exist
- **WHEN** application code references `App\Iceberg\Table\TableScan`, `App\Iceberg\Manifest\ManifestReader`, `App\Iceberg\Expression\Expression`, or `App\Iceberg\Parquet\ParquetReader`
- **THEN** the symbols resolve

#### Scenario: Read calls throw UnsupportedOperationException
- **WHEN** application code invokes `Table::newScan()` or any reader method in M1
- **THEN** the call throws `App\Iceberg\Exception\UnsupportedOperationException` with a message indicating the feature lands in M2

### Requirement: Catalog stubs for forward-compat

The library SHALL declare `App\Iceberg\Catalog\RestCatalog`, `HiveCatalog`, `GlueCatalog`, `NessieCatalog` and `App\Iceberg\Io\S3FileIO` as classes implementing the `Catalog` and `FileIO` interfaces respectively, so the M7/M8 changes add concretes without introducing new public types. M1 implementations SHALL throw `UnsupportedOperationException` on instantiation.

#### Scenario: Catalog and FileIO stubs exist
- **WHEN** application code references the stub classes
- **THEN** the symbols resolve

#### Scenario: Stub instantiation throws
- **WHEN** application code calls `new RestCatalog(…)` (or any other M7+ stub) in M1
- **THEN** the call throws `UnsupportedOperationException` with a message naming the milestone (M7 or M8)

### Requirement: Append-only ingest from Crashler in M1

Crashler's OTLP ingest path SHALL only invoke `AppendFiles` (via the `IcebergSinkWriter` adapter). It SHALL NOT issue `OverwriteFiles` or `RowDelta` operations from the request handler in M1. Operator-driven row erasure (e.g. via a future `crashler:iceberg:delete-rows` command) is out of scope for M1 and SHALL be added in a separate change.

#### Scenario: Ingest produces append snapshots only
- **WHEN** any snapshot produced by the OTLP ingest path is inspected
- **THEN** its `summary.operation` equals `append`

#### Scenario: No delete-issuing code paths in the ingest controllers
- **WHEN** an operator searches Crashler's controllers and ingest services
- **THEN** no call to `Table::newOverwrite` or `Table::newRowDelta` is found in those code paths

### Requirement: No background long-running workers in M1

The Iceberg writer SHALL NOT introduce a long-running consumer process, message broker, or scheduled-job dependency in M1. Batched commits SHALL be triggered inline (by whichever request crosses a threshold) or by an operator-run console command (`crashler:iceberg:flush`). A cron-driven flush is recommended but not required. Snapshot expiry, manifest rewrite, and orphan-file detection are out of scope for M1 and SHALL be introduced in M5.

#### Scenario: No new long-running processes
- **WHEN** an operator inspects the deployed process tree after applying this change
- **THEN** the only processes related to ingest are PHP-FPM workers, the web server, and the optional cron job running `crashler:iceberg:flush`

#### Scenario: No new console commands related to maintenance
- **WHEN** an operator lists registered Symfony console commands after applying this change
- **THEN** only `crashler:iceberg:flush` (and optionally `crashler:iceberg:recover`, run from deployment hooks) related to Iceberg appears
- **AND** no command for snapshot expiry, manifest rewrite, or compaction is registered (those land in M5)

### Requirement: Single-host POSIX filesystem assumption (M1)

The M1 writer SHALL assume a local POSIX filesystem with working `flock(2)`, `rename(2)` atomicity, and `fsync(2)`. M1 SHALL NOT support object-storage backends (S3, GCS, Azure Blob), network filesystems with broken `flock` semantics (NFS without lockd), or multi-host writers. The README SHALL document this requirement; M7 (`add-iceberg-rest-catalog`) is the planned multi-host upgrade path.

#### Scenario: Documented requirement
- **WHEN** an operator reads the README's Iceberg section
- **THEN** the requirement for local POSIX storage and the single-writer-host assumption is documented
- **AND** M7 is named as the future change that lifts this restriction

### Requirement: Operator hand-edit hazard documented

The README SHALL document that direct modification of files inside an Iceberg table tree (deleting a Parquet file, hand-editing a `metadata.json`, removing a manifest, mutating `.pending-index.json`) will leave the table in an inconsistent state and that Crashler does not detect or repair such tampering.

#### Scenario: Hazard documented
- **WHEN** an operator reads the README's Iceberg operations section
- **THEN** the hand-edit hazard is called out explicitly
- **AND** the recommended remediation (restore from the most recent intact `vN.metadata.json` and matching manifest set; flush pending-index before any manual operation) is included

### Requirement: Migration story documented (no automatic migration of legacy Hive trees)

The README SHALL document that this change is a no-BC migration: existing on-disk Hive Parquet files are left untouched but Crashler no longer reads or writes to them. Operators are responsible for archiving, deleting, or independently migrating those files. The README SHALL provide example DuckDB recipes for both the new Iceberg layout (`iceberg_scan`) and the legacy Hive layout (`read_parquet`) so existing dashboards remain queryable.

#### Scenario: Migration documented
- **WHEN** an operator reads the README's CHANGELOG / migration section
- **THEN** the no-BC nature of the change is explicit
- **AND** the legacy `<APP_SHARE_DIR>/<signal>/<tenant>/date=…/` tree is named as untouched-but-no-longer-written
- **AND** at least one DuckDB recipe is shown for each layout
