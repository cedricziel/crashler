## Context

Crashler runs as PHP-FPM behind a Symfony web app. Each OTLP request lands in a controller, is decoded, and is passed to a per-signal `*IngestService` which calls a `ParquetFileWriter`. Today the writer commits a single `.tmp + rename` per request into a Hive-partitioned tree under `<APP_SHARE_DIR>/<signal>/<tenant>/date=…/hour=…/`. There is no catalog, no manifest, no snapshot, and no concept of a "table" beyond the directory.

The user's hard constraints for this change:

- **Stay in PHP.** No Java/Rust/Python sidecar, no native extension wrapping `iceberg-cpp`. Native extensions would primarily accelerate Parquet encoding (already fast), at the cost of owning per-PHP/per-OS native builds.
- **Native extension was evaluated and rejected.** Iceberg metadata work per commit is microseconds either way.
- **Crashler transitions to writing Iceberg now. No backwards compatibility with the Hive writer.** The Hive layout is removed in this change. Existing on-disk Hive trees are left in place but no longer written to or read.
- **Optimize for committing larger chunks.** Per-request snapshots produce a 1:1 file-to-snapshot ratio; that's costly for both metadata size and downstream readers. Commits coalesce many data files into one snapshot via a configurable batch trigger.
- **Row-level deletes are first-class in the library API.** v2 supports positional and equality deletes; the public write builders (`AppendFiles`, `OverwriteFiles`, `RowDelta`) cover all of them. Crashler's ingest path is append-only and won't issue deletes from M1, but the surface is there for operators and library consumers.
- **Plan for extraction as a public Composer package later.** No PHP ecosystem package covers Iceberg today. M1 ships in-tree, but the architecture from M1 onward must be extractable without rewrites: clean public/internal API split, no Symfony or Crashler types in the public surface, explicit `Catalog`/`FileIO` interfaces.
- **Plan for full reader+writer coverage of Iceberg v2 (with v3 as a near-term target).** M1 ships a write-only slice covering the full v2 *write* surface. Reader, schema evolution, maintenance, additional catalogs, and v3 land in their own milestones; the M1 public types accommodate them without breaking changes.

A previous draft considered shipping a separate `cedricziel/iceberg-php` Composer package up front. That is rejected for M1: the surface is small enough to keep in-tree, and decisions about a public package API should follow at least one production user (Crashler itself). What changes versus the previous design is that we **commit the API discipline up front** so that the package extraction in M10 is a `git mv` plus a `composer.json` split, not a refactor.

## Goals / Non-Goals

**Goals (M1 of this change):**

- Crashler writes Iceberg tables for `logs`, `traces`, `metrics` instead of Hive-partitioned Parquet trees, with no opt-in switch and no fallback path.
- Produce valid Iceberg v2 tables that DuckDB's `iceberg` extension and PyIceberg can read without modification.
- Coalesce per-request data files into batched commits driven by file-count, byte, and age thresholds, plus an explicit operator-triggered flush command.
- Public write surface covers v2 in full: append, overwrite, row-delta, position deletes, equality deletes.
- Establish the public `App\Iceberg\*` namespace with API discipline that survives extraction unchanged through M2-M9.
- Keep the M1 implementation tractable (target: ≤4 kLoC including tests) and dependency-light (one Avro library, the existing Parquet writer, PSR-3 logger interface).

**Goals (architectural, applies across M1-M10):**

- The public namespace `App\Iceberg\*` is the future contents of `cedricziel/iceberg-php`. It MUST NOT depend on Symfony, Doctrine, or Crashler config.
- Every Iceberg spec concept has a value object whose field shape mirrors the spec exactly. v3-only fields are present as nullable from M1.
- Reader and writer share the same value objects and the same `Catalog` / `FileIO` interfaces.

**Non-Goals (M1, deferred to named follow-up changes):**

- A PHP-side Iceberg reader (M2). M1 stubs the read interfaces with `UnsupportedOperationException`.
- Schema evolution and partition-spec evolution mutators (M4). M1's value objects are fully shaped, but only the static "create from catalog YAML" construction path is wired.
- Snapshot expiry, manifest rewrite, file compaction, orphan-file detection (M5).
- REST/Hive/Glue/Nessie catalogs (M7, M8). Object-storage backends (S3/MinIO/GCS) (M7).
- Iceberg v3 features: variant type, v3 sequence-number tightening (M9).
- Standalone Composer package extraction (M10).
- Migration of existing on-disk Hive trees into Iceberg tables. (Operators can keep them for archival DuckDB reads or delete them.)

## Library architecture (binding from M1)

### A1. Public vs. internal namespace boundary

`App\Iceberg\*` is the **public** namespace. Everything inside it is the future content of the extracted Composer package and is bound by:

- No `use` import of any class outside `App\Iceberg\*` is permitted, with three exceptions:
  - PHP standard library and PSR interfaces (PSR-3 `LoggerInterface`; PSR-18 `ClientInterface` and PSR-17 message factories from M7 onward).
  - The Avro dependency (`wikimedia/avro`), and only inside `App\Iceberg\Avro\*`.
  - The Parquet dependency (`flow-php/parquet`), and only inside `App\Iceberg\Parquet\*`.
- No `Symfony\*`, no `Doctrine\*`, no Crashler `App\*` (except `App\Iceberg\*` itself). No `getenv()`, no Symfony parameter bag access, no `kernel.*` reads. Configuration arrives as constructor arguments.
- No global state. No service locator. No static caches that escape an instance lifetime.
- All exceptions thrown across the public surface extend `App\Iceberg\Exception\IcebergException`.

`App\Storage\Iceberg\*` is the **adapter** namespace, owned by Crashler. It bridges Symfony DI / env vars / Crashler types to the public `App\Iceberg\*` API. This is the only place Crashler-specific types meet Iceberg types.

A static-analysis rule (PHPStan custom rule or a regex linter run in CI) enforces these constraints. A violation fails the build.

### A2. Public surface (M1 + forward-compat shape)

Symbols below are part of the public API and stable across M1-M10 (additive evolution only):

```
App\Iceberg\
  Catalog\
    Catalog                        interface  - loadTable, createTable, tableExists, dropTable, listTables, renameTable, commit
    Namespace_                     value
    TableIdentifier                value
    FilesystemCatalog              concrete   (M1)
    RestCatalog                    concrete   (M7; interface stub in M1 throwing UnsupportedOperationException)
    HiveCatalog                    concrete   (M8)
    GlueCatalog                    concrete   (M8)
    NessieCatalog                  concrete   (M8)
  Io\
    FileIO                         interface  - newInputFile, newOutputFile, deleteFile, exists
    InputFile                      interface  - location, getLength, newStream
    OutputFile                     interface  - location, createOrOverwrite, createExclusive
    LocalFileIO                    concrete   (M1)
    S3FileIO                       concrete   (M7)
  Table\
    Table                          interface  - currentSnapshot, schema, spec, sortOrder, location, newScan, newAppend, newOverwrite, newRowDelta, newRewrite, expireSnapshots, history
    TableWriter                    facade     (M1; thin wrapper around the builders for adapter convenience)
    TableScan                      builder    (M2; M1 stub throws UnsupportedOperationException)
    AppendFiles                    builder    (M1) - appendFile(DataFile), appendFiles(iterable<DataFile>), commit(): Snapshot
    OverwriteFiles                 builder    (M1) - overwriteByRowFilter(Expression) [M2 to bind expression], addFile(DataFile), deleteFile(DataFile), commit()
    RowDelta                       builder    (M1) - addRows(DataFile), addDeletes(DeleteFile), validateNoConflictingDataFiles, validateDeletedFiles, commit()
    RewriteFiles                   builder    (M5)
  Metadata\
    TableMetadata                  value      - full v2 shape; v3 fields nullable from day one
    Schema                         value      - field-id-stable; mutators in M4
    PartitionSpec                  value      - spec-id-stable
    Transform                      sealed     (identity in M1; bucket/truncate/temporal in M4)
    SortOrder                      value      (only the unsorted instance in M1; full evolution in M4)
    Snapshot                       value
    SnapshotRef                    value      - branch / tag (full lifecycle in M9 polish)
    SnapshotSummary                value
  Manifest\
    Manifest                       value
    ManifestList                   value
    ManifestEntry                  value      - status (added | existing | deleted)
    DataFile                       value      - includes content (data | position-deletes | equality-deletes), equality_ids, sort_order_id, spec_id, full v2 stat fields nullable
    DeleteFile                     alias      - DataFile with content != data; type-aliased for builder ergonomics
    ManifestWriter                 concrete   (M1)
    ManifestListWriter             concrete   (M1)
    ManifestReader                 concrete   (M2)
    ManifestListReader             concrete   (M2)
    PositionDeleteWriter           concrete   (M1)
    EqualityDeleteWriter           concrete   (M1)
  Expression\
    Expression                     interface  (M2)
    Expressions                    helpers    (M2)
    BoundExpression                value      (M2)
  Avro\
    AvroCodec                      concrete   (M1; thin wrapper over wikimedia/avro)
    Schemas                        constant   (M1; hard-coded Iceberg manifest/manifest-list/delete-manifest schemas)
  Parquet\
    ParquetWriter                  adapter    (M1; thin wrapper over flow-php/parquet writer)
    ParquetReader                  adapter    (M2)
  Commit\
    CommitCoordinator              concrete   (M1; flock + version-hint + retry primitive used by FilesystemCatalog::commit)
    BatchedCommitDriver            concrete   (M1; coalesces accumulated DataFile / DeleteFile entries into one AppendFiles / RowDelta commit when thresholds fire)
    CommitRetryPolicy              value      (M1)
    BatchTriggerPolicy             value      (M1; max-files / max-bytes / max-age)
  Exception\
    IcebergException               base
    NoSuchTableException
    AlreadyExistsException
    CommitFailedException
    ValidationException
    UnsupportedOperationException
```

M1 implements: `Catalog\FilesystemCatalog`, `Io\LocalFileIO`, `Table\Table` and `Table\TableWriter`, `AppendFiles` / `OverwriteFiles` / `RowDelta` write builders (all functional), `Metadata\*` value objects, `Manifest\*` writers + `PositionDeleteWriter` + `EqualityDeleteWriter`, `Avro\AvroCodec`, `Parquet\ParquetWriter`, `Commit\CommitCoordinator`, `Commit\BatchedCommitDriver`, all M1 exceptions.

M1 stubs (throwing `UnsupportedOperationException`): `Catalog\RestCatalog`, `Catalog\HiveCatalog`, `Catalog\GlueCatalog`, `Catalog\NessieCatalog`, `Io\S3FileIO`, `Table\TableScan`, `Table\RewriteFiles`, `Manifest\ManifestReader`, `Manifest\ManifestListReader`, `Parquet\ParquetReader`, `Expression\*`, `expireSnapshots()`. Their interface signatures are committed to in M1 so future milestones add concretes without changing public types.

### A3. Configuration enters via constructor arguments only

The public namespace MUST NOT call `getenv()` or read configuration from any global. `Catalog`, `FileIO`, retry counts, lock timeouts, and batch triggers are constructor parameters. The Crashler adapter (`App\Storage\Iceberg\*`) translates env vars / Symfony parameters into these constructor arguments. This is what makes the package `composer require`able later without a Symfony bundle.

## Decisions

### D1. In-tree, but with extraction discipline (M1)

**Decision.** Implement the Iceberg library inside Crashler at `src/Iceberg/` (PSR-4 mapped to `App\Iceberg\`). Adapter code lives at `src/Storage/Iceberg/` (PSR-4 mapped to `App\Storage\Iceberg\`).

**Why.** A separate package implies stable API, semver, and an out-of-tree release cycle, none of which are useful before a real user (Crashler) has shaken the design. Without explicit boundary discipline, in-tree code accretes Symfony dependencies that have to be unwound; the fix is to enforce the boundary in CI from M1 (see A1, tasks §0).

### D2. Iceberg v2 wire format, v3-shaped value objects, full v2 write surface

**Decision.** M1 writes `format-version: 2`. Value objects in `App\Iceberg\Metadata\*` and `App\Iceberg\Manifest\*` carry **all** v2 fields plus the v3 fields known at design time (e.g. `last-partition-id`, `default-sort-order-id`, `refs`, `statistics`, `partition-statistics`), with v3-only fields nullable. M9 starts populating them and bumps `format-version` to 3.

**Write surface in M1**:
- `AppendFiles`: append data files (used by Crashler ingest)
- `OverwriteFiles`: overwrite data files matching a row filter or replace entire partitions (library users only in M1)
- `RowDelta`: add data files together with positional and/or equality delete files in one commit (library users only in M1)
- `PositionDeleteWriter`: emit Iceberg-format position-delete files (`file_path`, `pos`)
- `EqualityDeleteWriter`: emit Iceberg-format equality-delete files keyed by configured columns

Crashler's OTLP ingest path uses only `AppendFiles`. The other builders are public API for library consumers and for future Crashler features (e.g. operator-driven row erasure). Implementing them now costs roughly one writer per delete file format plus one builder per operation; the manifest-level shape is the same.

### D3. Filesystem catalog, version-hint pointer, `flock()` + optimistic retry

**Decision.** Each `<signal,tenant>` table is laid out as:

```
<APP_SHARE_DIR>/iceberg/<signal>/<tenant_slug>/
  metadata/
    v1.metadata.json
    v2.metadata.json
    …
    version-hint.text                          # contains the integer N matching v<N>.metadata.json
    .commit.lock                               # flock target
    .pending-index.json                        # see D10
    snap-<snapshot-id>-<ulid>.avro             # manifest list
    <ulid>.avro                                # manifest
    <ulid>-deletes.avro                        # delete-file manifest entries (when row-deltas committed)
  data/
    date=YYYY-MM-DD/
      hour=HH/
        part-<ulid>.parquet
  delete-data/
    date=YYYY-MM-DD/
      hour=HH/
        position-deletes-<ulid>.parquet
        equality-deletes-<ulid>.parquet
```

Commit primitive (used by `FilesystemCatalog::commit`):

1. Acquire `flock(LOCK_EX)` on `<table-root>/metadata/.commit.lock` with the configured lock timeout.
2. Read `version-hint.text` → current version `N`.
3. Read `vN.metadata.json` → current `TableMetadata`.
4. Verify the `expected-prior-snapshot-id` matches; if not, throw `CommitFailedException` so the caller can rebase and retry.
5. Write any new manifest, manifest list, or delete-manifest files (`.tmp`, fsync, rename).
6. Write `v(N+1).metadata.json.tmp`, fsync, rename.
7. Write `version-hint.text.tmp` containing `N+1`, fsync, rename.
8. Release lock.

Cleanup on failure mid-step-5-onward: unlink any `.tmp` and finalized files produced by this commit attempt before bubbling.

If `flock()` cannot be acquired within the timeout, the caller retries up to the configured retry count; each retry re-reads `version-hint.text` so it rebases.

**Why `flock()` and a retry loop, both?** `flock()` is sufficient for a single-host PHP-FPM deployment and avoids needing a true CAS primitive. The retry loop covers advisory-`flock` filesystems; the retry rebases on the latest version-hint.

**Why `Catalog\FilesystemCatalog::commit` rather than baking the protocol into the writer?** Future catalogs (REST, Hive, Glue) implement the same `Catalog::commit(TableMetadata previous, TableMetadata next)` signature with different commit primitives (HTTP CAS, Hive `alterTable`, Glue `UpdateTable`). Keeping the catalog interface narrow means the writer pipeline above the catalog is unchanged in M7/M8.

### D4. Batched commits — pending files + threshold-driven flush (replaces per-request commits)

**Decision.** Crashler no longer commits a snapshot per OTLP request. Instead:

1. **Per-request work (synchronous, inside the HTTP handler)**:
   - Encode Parquet, write `<APP_SHARE_DIR>/iceberg/<signal>/<tenant>/data/date=…/hour=…/part-<ulid>.parquet.tmp`.
   - fsync, rename to the final path. The data file is now durable on disk.
   - Append a one-line entry to `<table-root>/metadata/.pending-index.json` (held under `flock` very briefly) recording `(file_path, record_count, file_size_in_bytes, partition values, lower/upper bounds for time_unix_nano, min_time, write_timestamp_ms)`.
   - Return `200 OK`.
2. **Commit trigger (synchronous on whichever request crosses a threshold; or via cron-driven `crashler:iceberg:flush`)**:
   - After step 1, the request checks `.pending-index.json` length and aggregates. If any of the configured thresholds is crossed (`MAX_FILES`, `MAX_BYTES`, `MAX_AGE_MS` measured against the oldest pending entry's `write_timestamp_ms`), the request also performs a commit.
   - The commit acquires the table commit lock, reads the pending index, builds an `AppendFiles` containing every pending entry, calls `CommitCoordinator::commit`, and on success truncates the pending index.
   - On failure the pending index is left intact; the next request (or the next cron tick) will retry.
3. **Operator-triggered flush**: `crashler:iceberg:flush --signal logs --tenant acme` runs the same commit logic for the named (signal, tenant) pair. Without args it iterates all tables. Idempotent; safe to run on a 1-minute cron alongside the inline trigger.
4. **Crash-recovery**: at boot, an `IcebergPendingRecoveryWorker` (run by Symfony's kernel boot or a startup command) inspects all `.pending-index.json` files and drives a flush per table. This handles the case where workers wrote data files and updated the index, then crashed before triggering a commit.

**Why a pending index file?** Without it, "uncommitted" data files would have to be discovered by listing `data/` and diffing against the manifests. That works (M5's orphan-detection job will need it for other reasons) but is O(files) per discovery. The pending index gives the inline trigger O(1) checks, and the cost of maintaining it is one append-only line per request under a short-lived lock.

**Why not Symfony Messenger / a queue?** Adds a dependency (Redis/AMQP/DB transport), a long-running consumer process, and a new failure mode (queue lag visible in metrics). The pending-index approach uses what's already on disk and runs commit work on whatever PHP-FPM worker happens to cross the threshold — which is what the Hive writer already did for the simpler "one snapshot per request" case.

**Visibility contract.** A 200 means the data file is fsync'd and durable. Visibility in DuckDB / PyIceberg follows the next commit, bounded by `MAX_AGE_MS` (default 5 s) or by an explicit flush. This is documented in the README as the **only** semantic regression versus the Hive writer.

**Tunables.**
- `BATCH_MAX_FILES` (default 32): flush after 32 pending files for a tenant.
- `BATCH_MAX_BYTES` (default 64 MiB): flush after accumulated pending bytes exceed 64 MiB.
- `BATCH_MAX_AGE_MS` (default 5000): flush if the oldest pending file is older than 5 s.
- These can be set per-tenant in `config/packages/crashler.yaml` (overriding the defaults). Per-tenant overrides are wired through the adapter, not the public library.

### D5. Partition spec mirrors the Hive layout (M1)

**Decision.** Initial partition spec uses `identity(date)` and `identity(hour)`, where `date` and `hour` are top-level string columns derived from ingest time. Data files land under `data/date=YYYY-MM-DD/hour=HH/`. The `PartitionSpec` value object supports the full v2 transform set (`identity`, `bucket(N)`, `truncate(W)`, `year`, `month`, `day`, `hour`) but only `identity` is constructible in M1 — others throw `UnsupportedOperationException` from their factories. M4 turns them on.

**Why.** Iceberg readers do not require Hive-style paths — partition values come from the manifest — but keeping the on-disk path identical to the legacy layout means the data tree is visually familiar. Locking the `Transform` sealed type now means M4 can swap to `hour(time_unix_nano)` without changing call sites that consume `PartitionSpec`.

### D6. Avro: `wikimedia/avro`, narrowly wrapped

**Decision.** Depend on `wikimedia/avro`. `App\Iceberg\Avro\AvroCodec` exposes only `writeManifest()`, `writeManifestList()`, `writeDeleteManifest()`, `readManifest()` (M2), `readManifestList()` (M2). No other code in the public namespace imports `AvroIO*` types.

**Why.** The Iceberg Avro schemas are published and stable; we don't need a general Avro API. Wrapping the dependency means a future swap (e.g. to a hand-rolled encoder) is a single-file change.

**Fallback.** If `wikimedia/avro` cannot encode the manifest schemas correctly, fall back to a custom encoder. Validate this in the spike task before any other work depends on it.

### D7. No coexistence with Hive — clean break

**Decision.** The legacy `App\Storage\ParquetFileWriter`, `App\Storage\PartitionPathResolver`, the `<APP_SHARE_DIR>/<signal>/<tenant>/date=…/hour=…/` layout, and the per-request commit semantics are **deleted** in this change. There is no `CRASHLER_TABLE_FORMAT` switch, no fallback path, no shim.

**Why.** A switch doubles the test matrix for every storage-touching feature, and there's no operational benefit: the user explicitly wants to transition to Iceberg now. Existing on-disk Hive data is left in place; operators can keep it for archival DuckDB queries (the files are still valid Parquet) or delete it.

**Consequence.** Tenants on existing deployments will start producing files under a new on-disk root. DuckDB query recipes in the README change from `read_parquet('<APP_SHARE_DIR>/<signal>/<tenant>/**/*.parquet')` to `iceberg_scan('<APP_SHARE_DIR>/iceberg/<signal>/<tenant>/')`. Operators are responsible for updating their dashboards/queries; this is documented.

### D8. Reads stay external in M1; PHP reader lands in M2

**Decision.** M1 ships no PHP-side reader. Validation that produced tables are readable goes through DuckDB (`iceberg_scan('<table-root>')`) in a smoke test. M2 (`add-iceberg-reader`) implements `Table\TableScan`, `Manifest\ManifestReader`, `Expression\*`, `Parquet\ParquetReader`, **and read-side application of position + equality delete files**.

**Why.** A reader doubles the surface area. DuckDB and PyIceberg already provide solid Iceberg readers; competing with them in PHP is its own project. But the reader is on the roadmap because tooling (e.g. a PHP-side manifest inspector for ops) needs read access, and a PHP library that can't read its own writes has limited appeal as a Composer package.

### D9. Milestone roadmap (revised)

The library is staged across these milestones. Each is a separate OpenSpec change.

| Milestone | Change name (planned)                       | Delivers                                                                                                                                  | Public API growth                                                                       |
| --------- | -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| M1        | `add-iceberg-table-writer` (this change)     | Filesystem catalog; full v2 write surface (append, overwrite, row-delta, position+equality delete writers); batched commits; Crashler wired | `Catalog`, `FileIO`, `Table`, write builders, all M1 value objects, Avro codec, batched commit driver |
| M2        | `add-iceberg-reader`                         | `TableScan`, `ManifestReader`, `ManifestListReader`, `Expression\*`, partition pruning, primitive predicate push-down, time-travel, **delete-file application during scan**            | `TableScan`, `Expression`, `ParquetReader`                                              |
| M4        | `add-iceberg-schema-and-spec-evolution`      | `Schema.addColumn/dropColumn/renameColumn/promote`; partition-spec evolution; sort-order evolution; full `Transform` set                  | `SortOrder`, `Schema` mutators, `PartitionSpec` evolution, full transforms              |
| M5        | `add-iceberg-maintenance`                    | Snapshot expiry, manifest rewrite/compaction, orphan-file detection                                                                       | `expireSnapshots()`, `RewriteFiles`                                                     |
| M7        | `add-iceberg-rest-catalog`                   | REST catalog client (PSR-18); `S3FileIO`                                                                                                  | `RestCatalog`, `S3FileIO`                                                               |
| M8        | `add-iceberg-additional-catalogs`            | Hive Metastore, AWS Glue, Nessie catalog adapters                                                                                         | `HiveCatalog`, `GlueCatalog`, `NessieCatalog`                                           |
| M9        | `add-iceberg-v3-readiness`                   | Variant type, v3 sequence-number tightening, removed-snapshot-ref protocol; bump `format-version` to 3                                    | `Variant` type, v3 fields populated                                                     |
| M10       | `extract-iceberg-php-package`                | Move `App\Iceberg\` to `cedricziel/iceberg-php`, publish on Packagist, replace in-tree code with the dep                                  | The package itself                                                                      |

(M3 and M6 from the previous draft are folded: read-side delete application is part of M2, write-side delete API ships in M1.)

## Extraction plan (M10)

### E1. Target package

- **Name**: `cedricziel/iceberg-php`. Owner-namespaced under the maintainer's handle, signaling it is not a Crashler-only artifact.
- **License**: Apache-2.0 to match upstream Iceberg.
- **PHP requirement**: `^8.4`.
- **Hard runtime dependencies**:
  - `wikimedia/avro` (until and unless an in-package Avro encoder lands)
  - `flow-php/parquet`
  - `psr/log` (interface only)
  - `psr/http-client` + `psr/http-factory` (only relevant from M7; absent in earlier minor releases)
- **Forbidden hard dependencies**: anything `symfony/*`, `doctrine/*`, framework runtimes, native extensions beyond the Parquet codec extensions Crashler already documents.

### E2. Versioning and stability

- Pre-1.0 (`0.x`) until M2 (reader) and M4 (schema/spec evolution) are in.
- 1.0 ships when M1-M5 are merged and the package has been used in Crashler in production for at least one minor version.
- Post-1.0: strict semver; new Iceberg spec features arrive as additive minor releases.

### E3. In-tree → standalone migration mechanics

The M10 change executes:

1. Initialize the standalone repo with `App\Iceberg\*` source moved verbatim from `src/Iceberg/` to the new package's `src/`, namespace renamed `App\Iceberg\` → `Cedricziel\IcebergPhp\` by automated find/replace.
2. Move tests for the public namespace from Crashler's tree to the package's tree, with the same rewrite.
3. Publish a `0.x` release on Packagist.
4. In Crashler:
   - Remove `src/Iceberg/` and the corresponding tests.
   - `composer require cedricziel/iceberg-php:^0.x`.
   - Update `App\Storage\Iceberg\*` (the adapter, which doesn't move) to import from the new namespace.
   - Run the existing Iceberg integration tests; they should pass unchanged because the adapter is the only thing that ever touched the old namespace.
5. Tag the Crashler change as the "extraction commit" so previous in-tree history is preserved by `git log --follow`.

The constraint that makes this a one-day mechanical change rather than a multi-week refactor is **A1 (namespace boundary)** enforced from M1. If the boundary is intact, step 1 is verifiably correct because the public namespace has zero outbound references outside its own tree, PHP stdlib, PSR, and the Avro/Parquet deps.

## Risks / Trade-offs

- **`wikimedia/avro` may be insufficient for the manifest, manifest-list, or delete-manifest schemas.** Mitigation: §2 of `tasks.md` is a spike that round-trips hand-built manifests through the dependency before any other work depends on it.
- **`flock()` semantics on network filesystems.** Documented requirement: the storage root must be a local POSIX filesystem. M7 (REST catalog + S3 FileIO) supersedes this constraint.
- **Visibility latency from batching.** Documented: 200 means durable, not committed. Bounded by `BATCH_MAX_AGE_MS` (default 5 s). Operators who need synchronous visibility for a specific tenant can set that tenant's threshold to 0, at the cost of one commit per request.
- **Pending index can outlive a crashed worker.** Mitigation: startup recovery worker drives a flush on every table at boot.
- **Snapshot history growth (even with batching).** Slower than per-request because batching reduces snapshot rate, but still unbounded. M5 (snapshot expiry + manifest rewrite) is the planned mitigation; documented in the README as a known follow-up.
- **No-BC migration risk for existing operators.** Mitigation: documented in the README and CHANGELOG; existing on-disk Hive Parquet files remain readable by external DuckDB queries (just not by Crashler).
- **Designing the public API for features that don't exist yet (M2, M4, M5, M7-M9)** risks early-API misjudgement. Mitigated by:
  - Modeling value objects directly on the Iceberg spec, which is stable and externally maintained.
  - Only committing in M1 to interface signatures the M1 implementation can exercise. The other interfaces named in A2 are stubbed with `UnsupportedOperationException` and may be refined in their own milestones — additively, not breakingly, because the stubs throw rather than implementing partial behavior.
  - Cross-referencing PyIceberg and Iceberg-Java for harder-to-decide signatures (`Expression`, `TableScan`).
- **Row-level delete writers in M1 ship without a corresponding M1 reader.** Anyone who exercises `RowDelta` or `OverwriteFiles` from PHP in M1 must read the resulting tables with DuckDB or PyIceberg. Documented; acceptable because Crashler's ingest path doesn't use them in M1.
- **API-boundary linter is a new dependency.** Mitigation: start with a regex/grep-based check in CI (under 100 lines of bash); graduate to a PHPStan custom rule only if false positives appear.
- **Extraction is hypothetical until M10.** Mitigation: M1's architectural cost (a namespace boundary, enforced by a CI rule) is small even if M10 never happens. The public surface is also a useful internal seam for Crashler regardless of extraction — it keeps Iceberg logic from spreading into Symfony bundles.
