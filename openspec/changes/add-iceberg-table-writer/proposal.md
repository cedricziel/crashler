## Why

Crashler today writes Hive-partitioned Parquet files directly to a tenant-scoped tree. That layout is queryable by DuckDB but has no snapshot history, no atomic multi-file commits, no schema evolution metadata, no manifest-level pruning, and no concept of row-level deletes. As file count per tenant grows (one file per OTLP request), readers must list whole partitions and open every file to evaluate predicates.

Apache Iceberg v2 solves all of these with a small metadata layer over the same Parquet files we already produce. The user has explicitly ruled out a Java/Rust/Python sidecar and a native PHP extension wrapping `iceberg-cpp`: a native extension would primarily accelerate Parquet encoding (already fast in `flow-php/parquet`), at the cost of owning a per-PHP/per-OS native build. The metadata work itself — JSON + small Avro files — is microseconds of CPU per commit and a natural fit for pure PHP.

The PHP ecosystem has no maintained, full-spec Iceberg client. There is a real opening for `cedricziel/iceberg-php` to fill that gap, but the right way to find out whether such a library is worth maintaining is to run it in production inside Crashler first and only then extract it. This change introduces the library inside Crashler, in its own namespace, designed from day one with the API discipline required to extract later, and explicitly staged across milestones M1→M9 toward full reader+writer coverage of the Iceberg v2 spec (with hooks for v3).

**Crashler transitions to writing Iceberg now. There is no backwards-compatibility shim with the Hive writer.** The existing per-request, single-file Hive layout is removed by this change. Existing on-disk Hive trees are left untouched (operators may keep them for archival reads via DuckDB) but Crashler no longer writes to or reads from them.

## Strategic intent

This change ships the M1 slice that is necessary to make Iceberg the only writer:

- **Append-only writer** for OTLP ingest (logs, traces, metrics). This is what Crashler actually does in production.
- **Batched commits.** Per-request Parquet files are written immediately and durably, but commits coalesce many data files into one snapshot, with thresholds on file count, accumulated bytes, and oldest-file age. This is the principal scaling change vs. the legacy "one snapshot per request" model.
- **Row-level delete writers** (`PositionDeleteWriter`, `EqualityDeleteWriter`) and the public `OverwriteFiles` / `RowDelta` builders. Crashler's ingest path doesn't issue deletes today, but the library's write surface covers v2 fully so that operators (and future Crashler features like GDPR-style erasure) can use them without a follow-up library change.
- **Filesystem catalog only.** REST/Hive/Glue/Nessie ship in M7/M8.
- **No PHP-side reader.** DuckDB reads tables in production. M2 adds the reader for PHP-side tooling.

Future milestones (M2-M9) extend the library to a reader, schema evolution, maintenance (snapshot expiry / compaction), additional catalogs, object-storage backends, and Iceberg v3 readiness. M10 extracts the public namespace as a standalone Composer package. The roadmap is documented below; each milestone is its own change.

The architectural constraints that make extraction possible (clean public/internal API split, no Symfony or Crashler types in the public surface, explicit `Catalog`/`FileIO`/`Schema` interfaces) are **binding for M1**.

## What Changes (M1)

- New top-level `App\Iceberg\` namespace, partitioned into a public surface (`App\Iceberg\` directly — what would become the extracted package's root) and a Crashler-side adapter layer (`App\Storage\Iceberg\` — DI wiring, env-var binding, Crashler-specific glue). Nothing in `App\Iceberg\` may import Symfony, Doctrine, or Crashler config.
- M1 implementation inside `App\Iceberg\`:
  - `Catalog\Catalog` interface + `Catalog\FilesystemCatalog` concrete (only catalog in M1; REST/Hive/Glue/Nessie interfaces are committed to but not implemented).
  - `Io\FileIO` interface + `Io\LocalFileIO` concrete (only IO backend in M1).
  - Full v2 (and v3-shaped) value objects: `Metadata\TableMetadata`, `Schema`, `PartitionSpec`, `SortOrder`, `Snapshot`, `SnapshotRef`, `Manifest\Manifest`, `ManifestList`, `ManifestEntry`, `DataFile` (with `content` discriminator and `equality_ids`).
  - Avro readers and writers for manifest + manifest list (`Avro\AvroCodec`).
  - Parquet adapter (`Parquet\ParquetWriter`) wrapping `flow-php/parquet`.
  - Position-delete writer (`Manifest\PositionDeleteWriter`) and equality-delete writer (`Manifest\EqualityDeleteWriter`).
  - Public write builders: `Table\AppendFiles`, `Table\OverwriteFiles`, `Table\RowDelta`. M1 implements all three; Crashler's ingest path uses only `AppendFiles`.
  - `Commit\CommitCoordinator` implementing the M1 commit protocol (flock + version-hint + atomic rename + optimistic retry) and the **batched commit driver** (`Commit\BatchedCommitDriver`).
  - Public exception hierarchy `App\Iceberg\Exception\*`.
- Crashler-side adapter `App\Storage\Iceberg\IcebergSinkWriter` replaces the existing `ParquetFileWriter` in `*IngestService`. The legacy Hive-storage code (`ParquetFileWriter`, `PartitionPathResolver`, the `<APP_SHARE_DIR>/<signal>/<tenant>/date=…/hour=…/` layout) is **removed**.
- New `iceberg-storage` capability spec covering: M1 on-disk layout, batched commit protocol, partition spec, snapshot semantics, row-level delete write API, library extraction-readiness constraint, and DuckDB read compatibility.
- The legacy `log-storage`, `trace-storage`, `metric-storage` requirements about Hive layout, atomic per-request commits, ULID filenames, etc., are **REMOVED** and replaced with thin requirements that delegate to `iceberg-storage`.

## Roadmap (M2 → M9, each its own future change)

- **M2** (`add-iceberg-reader`): Reader. `Table\TableScan` with snapshot/time-travel, partition-spec evaluation, manifest-level predicate push-down for primitive comparisons, `FileScanTask` plan output, **read-side application of positional and equality delete files**. Reads still go through DuckDB in production; the PHP reader is for tooling, tests, and library consumers.
- **M3** ~~(`add-iceberg-row-level-deletes-read`)~~ — **folded into M2**. Read-side delete application is part of the reader.
- **M4** (`add-iceberg-schema-and-spec-evolution`): `Schema.addColumn/dropColumn/renameColumn/promote`; partition-spec evolution (new `spec-id`, all data files tagged); sort-order evolution; full `Transform` set (`bucket`, `truncate`, `year`, `month`, `day`, `hour`).
- **M5** (`add-iceberg-maintenance`): Snapshot expiry, manifest rewrite/compaction, orphan file detection, table-level metadata vacuuming. First Crashler-internal scheduler use.
- **M6** ~~(`add-iceberg-row-level-deletes-write`)~~ — **folded into M1**. Write-side delete API ships now; Crashler may exercise it later.
- **M7** (`add-iceberg-rest-catalog`): REST catalog client (PSR-18 HTTP). `Io\S3FileIO` (PSR-18 or AWS SDK behind interface).
- **M8** (`add-iceberg-additional-catalogs`): Hive Metastore, AWS Glue, Nessie catalog adapters.
- **M9** (`add-iceberg-v3-readiness`): Variant type, v3 sequence-number tightening, removed-snapshot-ref protocol; bump `format-version` to 3.
- **M10** (`extract-iceberg-php-package`): Move `App\Iceberg\` to `cedricziel/iceberg-php`, publish on Packagist, replace in-tree code with the published dependency.

Each milestone gates on the previous one being merged and exercised in Crashler. The roadmap is not a contract — milestones may be reordered, merged, or split — but M1's architecture pays the cost of keeping all of them open as options.

## Capabilities

### New Capabilities
- `iceberg-storage`: full v2 write surface (append, overwrite, row-delta), filesystem catalog, batched commit protocol, row-level delete writers, library extraction-readiness constraint.

### Modified Capabilities
- `log-storage`, `trace-storage`, `metric-storage`: legacy Hive-layout requirements are REMOVED; replaced with a single requirement delegating storage to `iceberg-storage`.

## Impact

- **No backwards compatibility.** The Hive path is removed in this change. Tenants on existing deployments will start writing Iceberg tables under a new on-disk root; previously written Hive Parquet files remain on disk but are not read or written by Crashler. Operators may keep them for archival DuckDB queries or delete them; this change does not migrate them.
- **New dependency**: `wikimedia/avro` (pure PHP). No new PHP extensions beyond what the Parquet writer already needs.
- **No new database tables**: Iceberg metadata lives entirely on the filesystem.
- **New configuration**:
  - `CRASHLER_ICEBERG_COMMIT_RETRIES` (default `5`)
  - `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS` (default `2000`)
  - `CRASHLER_ICEBERG_BATCH_MAX_FILES` (default `32`)
  - `CRASHLER_ICEBERG_BATCH_MAX_BYTES` (default `67108864` = 64 MiB)
  - `CRASHLER_ICEBERG_BATCH_MAX_AGE_MS` (default `5000`)
- **Removed configuration**: `CRASHLER_PARQUET_COMPRESSION` is preserved (Parquet codec selection still applies). The previously discussed `CRASHLER_TABLE_FORMAT` opt-in switch is **not introduced**; Iceberg is the only format.
- **No new routes or controllers**: the existing OTLP controllers call the existing services; only the storage adapter changes.
- **New namespace discipline**: `App\Iceberg\*` is the future public package surface and MUST NOT depend on Symfony, Doctrine, Crashler config, or any other Crashler-internal type. CI enforces this via a static analysis rule.
- **New on-disk layout**: `<APP_SHARE_DIR>/iceberg/<signal>/<tenant>/{metadata,data}/…`. The legacy `<APP_SHARE_DIR>/<signal>/<tenant>/date=…/hour=…/` tree is no longer written to.
- **New Symfony console command**: `crashler:iceberg:flush` — manually triggers a batched commit for tenants with pending files; runs idempotently. Used by operators and by an optional cron (recommended for low-rate tenants).
- **Out of scope for M1 (deferred to named follow-up changes)**: PHP-side reader (M2), schema/spec/sort-order evolution (M4), snapshot expiry/compaction (M5), REST/Hive/Glue/Nessie catalogs (M7, M8), object-storage backends (M7), v3 readiness (M9), package extraction (M10).
- **Tradeoffs accepted in M1**:
  - **Eventual visibility**: a 200 response means the Parquet data file is fsync'd on disk, but readers (DuckDB / PyIceberg) only see the rows after the next batched commit. The upper bound on visibility delay is `CRASHLER_ICEBERG_BATCH_MAX_AGE_MS`. This is a deliberate change from the Hive writer's "200 means visible" contract; documented in the README.
  - **Pending files** that haven't yet been committed live alongside committed files in `data/` but are not referenced by any snapshot. A startup repair task discovers and commits them.
  - **`flock()`-only single-host concurrency**: caps per-table commit throughput at one commit at a time. M7 (REST catalog) is the multi-host upgrade path.
  - **Designing the public API for features that don't exist yet (M2-M9)** risks early-API misjudgement; mitigated by keeping value-object shapes derived directly from the Iceberg spec and only exposing types in the public namespace once they are actually used (interfaces with no concrete throw `UnsupportedOperationException` in M1 and are filled in their own milestones).
