## Context

Crashler runs as PHP-FPM behind a Symfony web app. Each OTLP request lands in a controller, is decoded, and is passed to a per-signal `*IngestService` which calls a `ParquetFileWriter`. Today the writer commits a single `.tmp + rename` per request into a Hive-partitioned tree under `<APP_SHARE_DIR>/<signal>/<tenant>/date=…/hour=…/`. There is no catalog, no manifest, no snapshot, and no concept of a "table" beyond the directory.

The user's hard constraints for this change:

- **Stay in PHP.** No Java/Rust/Python sidecar, no native extension wrapping `iceberg-cpp`. The motivation for the constraint is operational, not ideological: every native dependency is a per-PHP/per-OS build to maintain, and a sidecar is a second long-running process to deploy and monitor.
- **Native extension was evaluated and rejected.** Iceberg metadata work per commit is a few-KB JSON file plus one or two small Avro files; that's microseconds either way. Native code wins on Parquet encoding/compression, not Iceberg metadata, so a hypothetical extension would be paying maintenance cost for the wrong layer of the stack.
- **Coexistence with the current Hive writer.** The Hive layout works and has tests; Iceberg is opt-in for v1.

A previous draft considered shipping a separate `crashler/iceberg-php` Composer package up front. That is rejected for v1: the surface is small enough to keep in-tree, and decisions about a public package API should follow at least one production user (crashler itself).

## Goals / Non-Goals

**Goals:**

- Produce valid Iceberg v2 tables that DuckDB's `iceberg` extension and PyIceberg can read without modification.
- Preserve the durability contract: a 200 response means the data file is fsync'd, the manifest is fsync'd, and the new metadata pointer has been atomically swapped.
- Coexist with the Hive writer behind a single environment switch so rollout is per-deployment.
- Keep the Iceberg implementation small (target: under ~2 kLoC including tests) and dependency-light (one Avro library plus the existing Parquet writer).

**Non-Goals:**

- A general-purpose PHP Iceberg client, a public Composer package, or a Java-feature-parity reader.
- Object-storage backends (S3/MinIO/GCS). All paths assume a POSIX filesystem with `flock()` and atomic `rename()`.
- REST/Hive/Glue/Nessie catalogs.
- Row-level deletes (positional or equality), merge-on-read, copy-on-write rewrites.
- Schema evolution beyond the initial schema declared in `config/schemas/<signal>/v<n>.yaml` (which already exists for the Hive path). Schema changes for Iceberg are a separate change.
- Snapshot expiry, manifest rewrite, file compaction, or any background job. These are correctness-preserving optimizations and belong in a follow-up.
- A PHP-side Iceberg reader. Reads stay in DuckDB / PyIceberg / Trino in v1.
- Migration of an existing Hive tree into an Iceberg table. New tenants opt in cleanly; existing data stays where it is until a separate migration change.

## Decisions

### D1. In-tree, pure-PHP writer

**Decision.** Implement the Iceberg writer inside crashler at `src/Iceberg/`. No separate Composer package in this change.

**Why.** The surface is small (table metadata + manifest list + manifest + commit protocol). A separate package implies a stable API, semver, and an out-of-tree release cycle, none of which are useful before a real user (crashler) has shaken the design. Extracting later is a mechanical move; designing for extraction up front is speculative.

**Layout.**

```
src/Iceberg/
  Catalog/FilesystemCatalog.php          # read/write version-hint, list metadata.json files
  Commit/CommitCoordinator.php            # flock + optimistic retry
  Metadata/TableMetadata.php              # value object for v2 metadata.json
  Metadata/TableMetadataWriter.php        # JSON serializer
  Metadata/PartitionSpec.php
  Metadata/Schema.php
  Metadata/Snapshot.php
  Manifest/ManifestList.php
  Manifest/ManifestListWriter.php         # Avro
  Manifest/Manifest.php
  Manifest/ManifestWriter.php             # Avro
  Manifest/DataFile.php
  Avro/AvroEncoder.php                    # thin wrapper over wikimedia/avro
  IcebergTableWriter.php                  # facade called by *IngestService
```

### D2. Iceberg v2, append-only

**Decision.** `format-version: 2`. Every commit is an `append` operation that adds exactly one data file and produces exactly one new snapshot.

**Why.** v2 is the current spec and is what every modern reader supports. Append-only matches crashler's workload (a log/error sink), eliminates delete-file machinery, and removes the equality/positional-delete schema branches from the manifest writer.

**Consequence.** No deletes means no `DELETE` FROM-style operations in PHP. If a future feature needs deletes, it's a separate change adding a delete-file writer. Out-of-band deletion (operator removes a Parquet file by hand) breaks the table; this is documented, not enforced.

### D3. Filesystem catalog, version-hint pointer, `flock()` + optimistic retry

**Decision.** Each `<signal,tenant>` table is laid out as:

```
<APP_SHARE_DIR>/iceberg/<signal>/<tenant_slug>/
  metadata/
    v1.metadata.json
    v2.metadata.json
    …
    version-hint.text          # contains the integer N matching v<N>.metadata.json
    snap-<snapshot-id>-<ulid>.avro     # manifest list
    <ulid>.avro                # manifest
  data/
    date=YYYY-MM-DD/
      hour=HH/
        part-<ulid>.parquet
```

Commit protocol per request:

1. Acquire `flock(LOCK_EX)` on `<table-root>/metadata/.commit.lock` with `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS`.
2. Read `version-hint.text` → current version `N`.
3. Read `vN.metadata.json` → current `TableMetadata` (cache parsed value within the lock).
4. Build new manifest containing the just-written data file; write `<ulid>.avro.tmp`, fsync, rename.
5. Build new manifest list referencing the new manifest plus the previous snapshot's manifest list contents (carried forward); write `snap-<snapshot-id>-<ulid>.avro.tmp`, fsync, rename.
6. Build `v(N+1).metadata.json` (new snapshot appended, `current-snapshot-id` updated, previous-snapshot history carried); write `.tmp`, fsync, rename.
7. Atomically replace `version-hint.text` (write `.tmp`, rename).
8. Release lock.

If any step fails after step 4, unlink the just-written `.tmp`/finalized files for the new manifest, manifest list, and `v(N+1).metadata.json` before releasing the lock and bubbling the error.

If `flock()` cannot be acquired within the timeout, retry the whole sequence up to `CRASHLER_ICEBERG_COMMIT_RETRIES` times; each retry re-reads `version-hint.text` so a writer that woke up to find a newer version simply rebases.

**Why `flock()` and a retry loop, both?** `flock()` is sufficient for a single-host PHP-FPM deployment and avoids the need for a true CAS primitive. The retry loop is there for the case where the lock file's filesystem reports `flock` as advisory and a non-cooperating process raced; the retry rebases on the latest version-hint and tries again. Together they're as safe as the filesystem allows without introducing a network coordination service.

**Why not pure optimistic concurrency (no lock)?** Without a CAS on `version-hint.text`, two concurrent writers that both produce `v(N+1).metadata.json` will race on the rename and one will silently overwrite the other's snapshot, losing data. Linux's `rename()` is atomic but not conditional; we'd need `renameat2(RENAME_NOREPLACE)` (Linux-only, not portable from PHP) to do CAS via rename. `flock()` is the portable, simple alternative.

**Why not a Postgres-backed catalog?** Crashler explicitly avoids touching the database in the ingest path. A DB catalog would reintroduce that coupling.

### D4. One snapshot per accepted request

**Decision.** Each OTLP request produces exactly one new snapshot containing one data file.

**Why.** Mirrors the current "one Parquet file per request" contract and keeps the writer stateless across requests. Alternatives (batching N requests into one snapshot) require a queue or a long-running process, both of which the Hive change explicitly rejected.

**Consequence.** Snapshot count grows linearly with ingest rate. At 1 req/s, that's 86 400 snapshots/day per tenant. Each adds ~1 KiB of metadata, so a year of uncompacted history is ~30 MiB per tenant — readable, not pretty. A future change SHALL introduce snapshot expiry + manifest rewrite. This change documents the limitation in the README and the spec.

### D5. Partition spec mirrors the Hive layout

**Decision.** Initial partition spec uses `identity(date)` and `identity(hour)`, where `date` and `hour` are top-level int32/string columns derived from ingest time. Data files land under `data/date=YYYY-MM-DD/hour=HH/`.

**Why.** Iceberg readers do not require Hive-style paths — partition values come from the manifest — but keeping the on-disk path identical to the current Hive layout means the data tree is visually familiar, and a tenant migrating from `hive` to `iceberg` mode keeps the same operational mental model.

**Consequence.** Iceberg's "hidden partitioning" via transforms (e.g., `hour(time_unix_nano)`) is not used in v1. A later change can switch to transform-based partitioning and rewrite manifests without touching data files.

### D6. Avro: `wikimedia/avro`, narrowly wrapped

**Decision.** Depend on `wikimedia/avro` (a maintained fork of `apache/avro`'s PHP language). Build a thin `Iceberg\Avro\AvroEncoder` wrapper that exposes only `writeManifest()` and `writeManifestList()` and hard-codes the Iceberg manifest/manifest-list Avro schemas.

**Why.** The Iceberg Avro schemas are published and stable; we don't need a general Avro API. Wrapping the dependency means a future swap (e.g., to a 200-line hand-rolled encoder targeted at exactly these two schemas) is a single-file change.

**Fallback.** If `wikimedia/avro` cannot encode the manifest schemas correctly (logical types, unions of records, default values), fall back to a custom encoder. Validate this in §3 of `tasks.md` before proceeding.

### D7. Coexistence switch

**Decision.** `CRASHLER_TABLE_FORMAT` env var with values `hive` (default) and `iceberg`. The `*IngestService` chooses between the legacy `ParquetFileWriter` (current behavior) and a new `IcebergTableWriter` based on this value at construction.

**Why.** No need to fork controllers, schemas, or DTOs. The Iceberg writer reuses the same row-encoding code and the same `flow-php/parquet` writer for the data file; only the post-write commit step differs.

**Consequence.** A single deployment writes either Hive *or* Iceberg, not both. A tenant cannot be migrated from one to the other by a config flip alone — the Iceberg metadata for their existing files would have to be backfilled. That backfill is a separate change.

### D8. Reads stay external

**Decision.** This change ships no PHP-side reader. Validation that produced tables are readable goes through DuckDB (`iceberg_scan('<table-root>')`) in a smoke test.

**Why.** A reader doubles the surface area. DuckDB and PyIceberg already provide solid Iceberg readers; competing with them in PHP would be a project of its own. Operators who want to query from PHP can shell out to DuckDB.

## Risks / Trade-offs

- **`wikimedia/avro` may be insufficient.** Mitigation: §3 of `tasks.md` is a spike that round-trips a hand-built manifest through the dependency before any further work depends on it.
- **`flock()` semantics on network filesystems.** Documented requirement: the storage root must be a local POSIX filesystem (the Hive writer has the same requirement; this is not a regression).
- **Snapshot history growth.** Documented in D4 and the spec; a follow-up "snapshot expiry" change is the planned mitigation.
- **Concurrent writes across hosts.** Out of scope; crashler is single-host today. Multi-host operation requires a real catalog (REST) and is a separate change.
- **Operator hand-edits the data tree.** Same risk as Hive mode but with sharper teeth: removing a Parquet file by hand makes the table inconsistent. Documented.
