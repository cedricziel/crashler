## Why

Crashler today writes Hive-partitioned Parquet files directly to a tenant-scoped tree. That layout is queryable by DuckDB, but it has no snapshot history, no atomic multi-file commits, no schema evolution metadata, and no manifest-level pruning. As the file count per tenant grows (one file per OTLP request), readers must list whole partitions and open every file to evaluate predicates.

Apache Iceberg v2 solves all four with a small, well-specified metadata layer on top of the same Parquet files we already produce. The user has explicitly ruled out a Java/Rust/Python sidecar and a native PHP extension wrapping `iceberg-cpp`: a native extension would primarily accelerate Parquet encoding (which is already fast in `flow-php/parquet` and not the metadata bottleneck), at the cost of owning a per-PHP/per-OS native build. The metadata work itself — JSON + a small Avro file per commit — is kilobytes of CPU per request and a natural fit for pure PHP.

This change introduces an embedded, pure-PHP Iceberg writer inside crashler, scoped to local filesystem catalog and append-only writes. It coexists with the current Hive writer behind an opt-in switch so the rollout can be staged.

## What Changes

- New `App\Iceberg\` namespace housing a self-contained Iceberg v2 writer:
  - Table metadata (`metadata.json`) writer
  - Manifest list writer (Avro)
  - Manifest writer (Avro), including data-file entries and bounds
  - Filesystem catalog with `version-hint.text` pointer and `flock()`-serialized commits per `<signal,tenant>` table
  - Optimistic-concurrency retry on commit conflict
- New `iceberg-storage` capability spec covering on-disk layout, commit protocol, partition spec, and snapshot semantics.
- Opt-in switch `CRASHLER_TABLE_FORMAT` (`hive` default, `iceberg` enables the new path). When `iceberg`:
  - Each `<signal>/<tenant>` becomes an Iceberg table rooted at `<APP_SHARE_DIR>/iceberg/<signal>/<tenant_slug>/`.
  - Data files still land under `data/date=YYYY-MM-DD/hour=HH/part-<ulid>.parquet` (mirrors the current Hive layout for visual continuity).
  - Each accepted request appends one data file in a new snapshot.
- Avro encoder: depend on `wikimedia/avro` (the maintained fork of `apache/avro`); a thin wrapper exposes only the manifest/manifest-list schemas and shields call sites from the upstream API.
- New DuckDB query recipes in the README using `iceberg_scan('<table-root>')`.

## Capabilities

### New Capabilities
- `iceberg-storage`: Iceberg v2 table writer (filesystem catalog, append-only snapshots), partition spec mirroring the current Hive layout, and the commit protocol (lock + version-hint + atomic rename + optimistic retry).

### Modified Capabilities
- `log-storage`, `trace-storage`, `metric-storage`: each gains a "when `CRASHLER_TABLE_FORMAT=iceberg`, the per-request file is committed as a new Iceberg snapshot" requirement, layered on top of the existing Hive behavior. The Hive layout remains the default and is unchanged when the switch is off.

## Impact

- **New dependency**: `wikimedia/avro` (pure PHP). No new PHP extensions required beyond what the Parquet writer already needs.
- **No new database tables**: Iceberg metadata lives entirely on the filesystem next to the data files.
- **New configuration**: `CRASHLER_TABLE_FORMAT` (default `hive`); `CRASHLER_ICEBERG_COMMIT_RETRIES` (default `5`); `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS` (default `2000`).
- **No new routes or controllers**: the existing OTLP controllers call the writer; only the writer's commit step changes when Iceberg mode is on.
- **New on-disk layout** (Iceberg mode only): `<APP_SHARE_DIR>/iceberg/<signal>/<tenant>/{metadata,data}/…`. The legacy `<APP_SHARE_DIR>/<signal>/<tenant>/date=…/hour=…/` tree is untouched while `CRASHLER_TABLE_FORMAT=hive`.
- **Out of scope (deliberately deferred)**: REST/Hive/Glue/Nessie catalogs, object-storage backends (S3/MinIO), row-level deletes (positional or equality), merge-on-read, schema evolution beyond the initial spec, snapshot expiry / compaction, manifest rewrite, time-travel reads from PHP, a PHP-side Iceberg reader, migration of an existing Hive tree into an Iceberg table.
- **Tradeoffs accepted in v1**: one snapshot per OTLP request produces a high snapshot rate per tenant; long-lived tables will need a compaction/expire change before the metadata footprint stops being negligible. `flock()` serialization caps per-table write throughput at the rate of metadata commit rather than the rate of Parquet writes; this is acceptable while crashler runs on a single host. Reads remain external (DuckDB / PyIceberg / Trino).
