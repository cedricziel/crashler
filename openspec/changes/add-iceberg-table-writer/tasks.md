**Methodology.** Every section below is TDD: for each implementation task, a failing test is written first, observed to fail for the expected reason, then minimum production code is written to make it green, then refactored with tests green. Tasks marked `[red]` write a failing test; tasks marked `[green]` add the implementation that makes the most recently red test pass.

## 0. API boundary discipline (binding from M1)

- [ ] 0.1 Add a CI step (`composer iceberg:lint-boundary`) that fails when any file under `src/Iceberg/` contains a `use` statement whose target namespace is not one of: PHP stdlib, `Psr\*`, `App\Iceberg\*`, `Wikimedia\Avro\*` (only inside `App\Iceberg\Avro\*`), `Flow\Parquet\*` (only inside `App\Iceberg\Parquet\*`).
- [ ] 0.2 Implementation: regex-based bash/PHP script under `bin/iceberg-lint-boundary`. Output: list of offending file:line:use-line tuples. Exit non-zero on any match.
- [ ] 0.3 Wire into CI (GitHub Actions / equivalent) and `composer test`.
- [ ] 0.4 Document the rule and how to run it locally in the README's "Working on the Iceberg library" section.

## 1. Project setup and dependencies

- [ ] 1.1 Add production dep `wikimedia/avro` (or current maintained fork). Run `composer require`.
- [ ] 1.2 Add `psr/log` if not already present.
- [ ] 1.3 Add env vars to `.env` and `.env.test`:
  - `CRASHLER_ICEBERG_COMMIT_RETRIES=5`
  - `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS=2000`
  - `CRASHLER_ICEBERG_BATCH_MAX_FILES=32`
  - `CRASHLER_ICEBERG_BATCH_MAX_BYTES=67108864`
  - `CRASHLER_ICEBERG_BATCH_MAX_AGE_MS=5000`
- [ ] 1.4 Bind env vars to container parameters in `config/services.yaml`: `crashler.iceberg.commit_retries`, `crashler.iceberg.lock_timeout_ms`, `crashler.iceberg.batch_max_files`, `crashler.iceberg.batch_max_bytes`, `crashler.iceberg.batch_max_age_ms`.
- [ ] 1.5 Add per-tenant overrides under `config/packages/crashler.yaml` `tenants.<slug>.iceberg.batch_max_*`; defaults inherited from the global parameters.
- [ ] 1.6 Update README "Optional PHP extensions": no new extensions; flag DuckDB ≥ 0.10 with the `iceberg` extension as the recommended reader.
- [ ] 1.7 Update README CHANGELOG section: announce the no-BC migration to Iceberg and the change in the on-disk root from `<APP_SHARE_DIR>/<signal>/<tenant>/` to `<APP_SHARE_DIR>/iceberg/<signal>/<tenant>/`.

## 2. Avro encoding spike — validate `wikimedia/avro` upstream

- [ ] 2.1 [red] One-shot script `tests/Iceberg/Spike/manifest_roundtrip_test.php`: build a minimal v2 manifest record via `wikimedia/avro` against the published v2 manifest schema, write to disk, re-read via Python (`pip install pyiceberg`) in a sub-shell. Assert byte-level round-trip and field equality.
- [ ] 2.2 [red] Same for the manifest-list schema.
- [ ] 2.3 [red] Same for the delete-manifest entries (data files with `content = position-deletes` and `content = equality-deletes`).
- [ ] 2.4 [green] If §2.1-2.3 pass, mark the dependency choice confirmed and remove the spike scripts.
- [ ] 2.5 [red] If any of §2.1-2.3 fails on a specific Avro feature (logical types, unions, defaults), document the failure mode in `design.md` under "D6 Fallback".
- [ ] 2.6 [green] (only if §2.5 triggers) Implement a hand-rolled Avro encoder targeted at exactly the manifest, manifest-list, and delete-manifest schemas; pass the round-trip tests against the new encoder.

## 3. App\Iceberg\Metadata — value objects (TDD)

- [ ] 3.1 [red] Unit test: `Schema::fromCatalogSchema(SchemaDefinition $def)` produces an Iceberg-shaped schema with `schema-id: 0`, one field per column, correct type mapping (`int32`→`int`, `int64`→`long`, `string`→`string`), required vs optional repetition, monotonic `field-id` from 1.
- [ ] 3.2 [green] Implement `Schema` and `Schema::fromCatalogSchema`.
- [ ] 3.3 [red] Unit test: a `PartitionSpec` with `identity(date)` + `identity(hour)` serializes to v2 JSON shape with `spec-id: 0` and two fields whose `source-id` references schema field-ids.
- [ ] 3.4 [green] Implement `PartitionSpec` value object and serialization.
- [ ] 3.5 [red] Unit test: `PartitionSpec::Builder::bucket(N)` / `truncate(W)` / `year` / `month` / `day` / `hour` factories throw `UnsupportedOperationException` in M1.
- [ ] 3.6 [green] Add the throwing factories.
- [ ] 3.7 [red] Unit test: `SortOrder::unsorted()` constructs the empty sort order with `order-id: 0`.
- [ ] 3.8 [green] Implement `SortOrder` value object.
- [ ] 3.9 [red] Unit test: `TableMetadata::initial(uuid, location, Schema, PartitionSpec, SortOrder)` for an empty table has `format-version: 2`, `last-sequence-number: 0`, `current-snapshot-id: null`, empty `snapshots`, single-element `schemas`/`partition-specs`/`sort-orders`. Nullable v3 fields (`refs`, `statistics`, `last-partition-id`, `default-sort-order-id`) present and `null` (or sensible default).
- [ ] 3.10 [green] Implement `TableMetadata` and `TableMetadata::initial`.
- [ ] 3.11 [red] Unit test: `TableMetadataWriter::write` produces JSON that round-trips through `json_decode` to an equal array.
- [ ] 3.12 [green] Implement the writer (stable key order, no pretty-printing).
- [ ] 3.13 [red] Unit test: `TableMetadataParser::read` parses a known-good v2 metadata JSON into a `TableMetadata` whose value-object equality matches the original.
- [ ] 3.14 [green] Implement the parser.
- [ ] 3.15 [red] Unit test: appending a snapshot via `TableMetadata::withAppendedSnapshot()` increments `last-sequence-number`, sets `current-snapshot-id`, prepends to `snapshot-log`, preserves prior snapshots.
- [ ] 3.16 [green] Implement `withAppendedSnapshot`.
- [ ] 3.17 [red] Unit test: `Snapshot` with `summary.operation = 'append'` and `summary.operation = 'overwrite'` and `summary.operation = 'delete'` all serialize to the documented v2 shape.
- [ ] 3.18 [green] Implement `Snapshot` and `SnapshotSummary`.

## 4. App\Iceberg\Manifest — value objects, writers, delete-file writers (TDD)

- [ ] 4.1 [red] Unit test: `DataFile` value object exposes all v2 fields including `content` (data | position-deletes | equality-deletes), `equality_ids`, `sort_order_id`, `spec_id`, plus nullable stat fields (`column_sizes`, `value_counts`, `null_value_counts`, `lower_bounds`, `upper_bounds`, `key_metadata`, `split_offsets`).
- [ ] 4.2 [green] Implement `DataFile`. Type-alias `DeleteFile = DataFile` (same shape; runtime guards check `content != data`).
- [ ] 4.3 [red] Unit test: `ManifestEntry` exposes `status` (added | existing | deleted) and a `data_file` field.
- [ ] 4.4 [green] Implement `ManifestEntry`.
- [ ] 4.5 [red] Component test: `ManifestWriter::write($manifest, $tmpPath)` produces an Avro file that PyIceberg's `ManifestFile.read` parses without error and returns the same data-file entries.
- [ ] 4.6 [green] Implement `ManifestWriter` against the v2 Avro schema.
- [ ] 4.7 [red] Component test: a manifest with two data files (one `content=data`, one `content=position-deletes`) round-trips with both entries in declared order; `equality_ids` round-trips for an `content=equality-deletes` entry.
- [ ] 4.8 [green] Add full content-type handling.
- [ ] 4.9 [red] Component test: `ManifestListWriter::write` produces an Avro file that PyIceberg reads back with the expected `manifest_path`, `added_data_files_count`, `partition_summaries`.
- [ ] 4.10 [green] Implement `ManifestListWriter`.
- [ ] 4.11 [red] Component test: `PositionDeleteWriter::write([(file_path, pos), …], $tmpPath)` produces a Parquet file at the documented Iceberg position-delete schema (`file_path: string`, `pos: long`); PyIceberg reads back the same rows.
- [ ] 4.12 [green] Implement `PositionDeleteWriter` (delegates to `App\Iceberg\Parquet\ParquetWriter`).
- [ ] 4.13 [red] Component test: `EqualityDeleteWriter::write($rows, $equalityFieldIds, $tmpPath)` produces a Parquet file containing only the equality columns; PyIceberg reads back the rows.
- [ ] 4.14 [green] Implement `EqualityDeleteWriter`.

## 5. App\Iceberg\Parquet — adapter (TDD)

- [ ] 5.1 [red] Component test: `ParquetWriter::open($outputFile, $schema, $compression)` and `writeRows(iterable<array>): WrittenFileStats` produces a valid Parquet file readable by `flow-php/parquet`'s reader; `WrittenFileStats` exposes record_count, file_size, and row-group statistics for the `time_unix_nano` column.
- [ ] 5.2 [green] Implement `ParquetWriter` wrapping `flow-php/parquet`. No `Flow\Parquet\*` types appear outside this file.

## 6. App\Iceberg\Io — LocalFileIO (TDD)

- [ ] 6.1 [red] Component test using a temp dir: `LocalFileIO::newOutputFile(path)::createOrOverwrite()` writes a stream that, when closed, produces the file at the given path.
- [ ] 6.2 [green] Implement `LocalFileIO`, `LocalInputFile`, `LocalOutputFile`.
- [ ] 6.3 [red] Component test: `createExclusive()` fails if the target already exists.
- [ ] 6.4 [green] Add the exclusive-create branch.
- [ ] 6.5 [red] Component test: `S3FileIO`, `RestCatalog`, `HiveCatalog`, `GlueCatalog`, `NessieCatalog` each throw `UnsupportedOperationException` on instantiation in M1.
- [ ] 6.6 [green] Add stub classes.

## 7. App\Iceberg\Avro — codec (TDD)

- [ ] 7.1 [red] Component test (uses §4 round-trip tests as exemplars): `AvroCodec::writeManifest($manifest, $outputFile)` produces an Avro file readable by PyIceberg.
- [ ] 7.2 [green] Implement `AvroCodec`. Hard-code the manifest, manifest-list, and delete-manifest schemas in `Avro\Schemas`.
- [ ] 7.3 [red] Component test: `AvroCodec::readManifest`, `readManifestList` throw `UnsupportedOperationException` in M1 (deferred to M2).
- [ ] 7.4 [green] Add the throwing stubs.

## 8. App\Iceberg\Catalog — FilesystemCatalog (TDD)

- [ ] 8.1 [red] Component test using `TempStorageRoot`: `FilesystemCatalog::createTable(identifier, schema, spec, sortOrder)` creates `metadata/v1.metadata.json` and `metadata/version-hint.text` containing `1`.
- [ ] 8.2 [green] Implement `FilesystemCatalog::createTable`.
- [ ] 8.3 [red] Test: `tableExists` returns true after creation; `loadTable` returns a `Table` whose `currentSnapshot()` is null and whose schema/spec match.
- [ ] 8.4 [green] Implement `tableExists`, `loadTable`.
- [ ] 8.5 [red] Test: `createTable` against an existing table throws `AlreadyExistsException`.
- [ ] 8.6 [green] Add idempotency check (or strict-throw, consistent with Iceberg-Java semantics).
- [ ] 8.7 [red] Test: `loadTable` against a missing table throws `NoSuchTableException`.
- [ ] 8.8 [green] Add the missing-table branch.
- [ ] 8.9 [red] Test: `commit(previousMetadata, nextMetadata)` writes `v(N+1).metadata.json.tmp`, fsyncs, renames, then atomically replaces `version-hint.text`.
- [ ] 8.10 [green] Implement `commit` (no concurrency control yet — that's §9).
- [ ] 8.11 [red] Test: `commit` with a `previousMetadata.last-sequence-number` not matching the on-disk current throws `CommitFailedException` and leaves no `.tmp` files.
- [ ] 8.12 [green] Add the version check.
- [ ] 8.13 [red] Test: `dropTable($id, purge=true)` removes the table root entirely; `purge=false` removes only the metadata.
- [ ] 8.14 [green] Implement `dropTable`.
- [ ] 8.15 [red] Test: `listTables(namespace)` returns the identifiers under the namespace path.
- [ ] 8.16 [green] Implement `listTables`.

## 9. App\Iceberg\Commit — CommitCoordinator (TDD)

- [ ] 9.1 [red] Component test: `CommitCoordinator::commit($tableRoot, callable $build)` acquires `flock` on `metadata/.commit.lock`, invokes `$build` with the current `TableMetadata`, and commits the returned next-metadata via `FilesystemCatalog`.
- [ ] 9.2 [green] Implement single-attempt commit with `flock` + `flock(LOCK_UN)`.
- [ ] 9.3 [red] Test: when `flock` would block, the coordinator waits up to `lock_timeout_ms` and then throws `LockTimeoutException`.
- [ ] 9.4 [green] Add timeout (`flock(LOCK_EX|LOCK_NB)` with a deadline loop).
- [ ] 9.5 [red] Test: when `commit` throws `CommitFailedException`, the coordinator releases the lock, re-reads the latest metadata, re-invokes the build callback, and retries up to `commit_retries` times.
- [ ] 9.6 [green] Add the retry loop.
- [ ] 9.7 [red] Test: build callback throwing a non-conflict exception aborts the commit immediately and bubbles; no `.tmp` files remain.
- [ ] 9.8 [green] Wire the cleanup path.
- [ ] 9.9 [red] Concurrency simulation: two forked processes racing on the same table both succeed and produce two distinct snapshots in deterministic order.
- [ ] 9.10 [green] Confirm; refactor only if the simulation reveals a bug.

## 10. App\Iceberg\Table — write builders (TDD)

- [ ] 10.1 [red] Test: `Table::newAppend()->appendFile($dataFile)->commit()` produces a snapshot with `summary.operation = 'append'`, one new manifest, one data-file entry, `current-snapshot-id` updated.
- [ ] 10.2 [green] Implement `AppendFiles`.
- [ ] 10.3 [red] Test: `AppendFiles::appendFiles(iterable)` accepts batched input and produces one snapshot containing all entries.
- [ ] 10.4 [green] Add batched append.
- [ ] 10.5 [red] Test: `Table::newRowDelta()->addRows($df1)->addDeletes($pdf1)->addDeletes($edf1)->commit()` produces a snapshot with `summary.operation = 'overwrite'`, a manifest containing the data file, and a delete-manifest containing both delete files.
- [ ] 10.6 [green] Implement `RowDelta`.
- [ ] 10.7 [red] Test: `Table::newOverwrite()->overwriteByRowFilter(Expressions::alwaysTrue())->addFile($df)->commit()` produces an `overwrite` snapshot that supersedes prior data files.
- [ ] 10.8 [green] Implement `OverwriteFiles` (M1 supports only `alwaysTrue` filter; M2 binds expressions).
- [ ] 10.9 [red] Test: `TableScan`, `RewriteFiles`, `expireSnapshots` throw `UnsupportedOperationException` in M1.
- [ ] 10.10 [green] Add the throwing stubs.

## 11. App\Iceberg\Commit\BatchedCommitDriver (TDD)

- [ ] 11.1 [red] Component test: `BatchedCommitDriver::recordPending($tableRoot, $dataFile, $writeTimestampMs)` appends an entry to `metadata/.pending-index.json` under a brief `flock`. Two concurrent appends both land.
- [ ] 11.2 [green] Implement `recordPending`.
- [ ] 11.3 [red] Test: `BatchedCommitDriver::shouldFlush(BatchTriggerPolicy)` returns true when pending file count ≥ `MAX_FILES`.
- [ ] 11.4 [red] Test: `shouldFlush` returns true when accumulated pending bytes ≥ `MAX_BYTES`.
- [ ] 11.5 [red] Test: `shouldFlush` returns true when oldest pending entry's age ≥ `MAX_AGE_MS`.
- [ ] 11.6 [red] Test: `shouldFlush` returns false when none of the thresholds is hit.
- [ ] 11.7 [green] Implement `shouldFlush`.
- [ ] 11.8 [red] Component test: `BatchedCommitDriver::flush($tableRoot)` reads the pending index, builds an `AppendFiles` containing every entry, calls `Catalog::commit`, and on success truncates the pending index.
- [ ] 11.9 [green] Implement `flush`.
- [ ] 11.10 [red] Test: `flush` failure leaves the pending index intact for retry.
- [ ] 11.11 [green] Add the failure path.
- [ ] 11.12 [red] Concurrency simulation: 100 concurrent `recordPending` calls followed by one `flush` produce a snapshot containing exactly 100 data-file entries.
- [ ] 11.13 [green] Confirm; tighten the brief `flock` if needed.

## 12. App\Storage\Iceberg — Crashler adapter (TDD)

- [ ] 12.1 [red] Component test: `IcebergSinkWriter::writeAndCommit($rows, $partitionValues, $tenant, $signal)` writes a Parquet data file under `<APP_SHARE_DIR>/iceberg/<signal>/<tenant>/data/date=…/hour=…/`, calls `BatchedCommitDriver::recordPending`, and conditionally calls `flush` when `shouldFlush()` returns true.
- [ ] 12.2 [green] Implement `IcebergSinkWriter`.
- [ ] 12.3 [red] Test: first request to a never-seen-before table auto-initializes via `FilesystemCatalog::createTable` before the first `recordPending`.
- [ ] 12.4 [green] Add lazy initialization (idempotent, race-safe via the same `flock`).
- [ ] 12.5 [red] Test: `CatalogResolver::resolve($tenant, $signal)` returns a per-(tenant, signal) `Catalog` instance with the correct table root.
- [ ] 12.6 [green] Implement `CatalogResolver`.
- [ ] 12.7 [red] Test: per-tenant overrides for `BATCH_MAX_*` from `crashler.yaml` are honored over the global defaults.
- [ ] 12.8 [green] Wire per-tenant overrides through `CrashlerCatalogFactory`.

## 13. Remove Hive writer (no-BC)

- [ ] 13.1 [red] Test (functional): with no `CRASHLER_TABLE_FORMAT` env var configured, `POST /v1/logs` writes an Iceberg table (not a Hive file).
- [ ] 13.2 [green] Replace the `ParquetFileWriter` injection in `*IngestService` with `IcebergSinkWriter`.
- [ ] 13.3 Delete `src/Storage/ParquetFileWriter.php`, `src/Storage/PartitionPathResolver.php`, `src/Storage/UlidFilenameGenerator.php`, `src/Storage/ParquetCompression.php`, `src/Storage/ParquetSchema.php`, and any other Hive-only classes.
- [ ] 13.4 Delete the corresponding tests under `tests/Component/Storage/` and `tests/Unit/Storage/`.
- [ ] 13.5 Remove `CRASHLER_PARQUET_COMPRESSION` env handling iff it was Hive-only; otherwise re-bind to the new `Parquet\ParquetWriter` adapter.
- [ ] 13.6 Update `config/services.yaml` to remove the legacy storage service definitions.
- [ ] 13.7 Update `composer.json` scripts: any `composer test` invocation that referenced Hive-only test paths is updated.
- [ ] 13.8 Verify that no code path under `App\` outside the new `App\Storage\Iceberg\*` constructs a Hive-style filename.

## 14. Operator console command — `crashler:iceberg:flush` (TDD)

- [ ] 14.1 [red] Functional test: `bin/console crashler:iceberg:flush --signal=logs --tenant=acme` triggers a flush against `<APP_SHARE_DIR>/iceberg/logs/acme/` if pending entries exist; exits 0 even when there is nothing to flush.
- [ ] 14.2 [green] Implement the command, delegating to `BatchedCommitDriver::flush`.
- [ ] 14.3 [red] Test: without args, the command iterates all known tables and flushes each.
- [ ] 14.4 [green] Iterate via `Catalog::listTables` per signal namespace.
- [ ] 14.5 [red] Test: concurrent flush against the same table is safe (one wins the `flock`, the other returns no-op).
- [ ] 14.6 [green] Confirm via the existing lock semantics; add a clear "skipped: held by another writer" log line.

## 15. Crash recovery on boot

- [ ] 15.1 [red] Test: with `metadata/.pending-index.json` containing 5 entries and no `flock` held, kernel boot triggers a flush and the entries become a snapshot.
- [ ] 15.2 [green] Implement `IcebergPendingRecoveryWorker` invoked from a Symfony boot hook (or a `crashler:iceberg:recover` command run from a deployment hook — the choice MUST NOT block boot, so async/once-on-deploy is acceptable).
- [ ] 15.3 [red] Test: pending-index entries pointing at non-existent data files are dropped (logged) and do not block flush of the remaining entries.
- [ ] 15.4 [green] Add the missing-file tolerance.

## 16. Functional ingest tests (TDD, end-to-end)

- [ ] 16.1 [red] Functional test: 50 sequential `POST /v1/logs` requests for `acme` produce a single snapshot once the file-count threshold is crossed; DuckDB `SELECT count(*) FROM iceberg_scan('<table-root>')` returns the sum of records.
- [ ] 16.2 [green] Confirm via existing wiring.
- [ ] 16.3 [red] Functional test: a single request followed by a `BATCH_MAX_AGE_MS` wait followed by another request causes the second request to drive a flush; the snapshot contains both files' rows.
- [ ] 16.4 [green] Confirm.
- [ ] 16.5 [red] Functional test: 32 concurrent requests (driven by `oha` or PHP's parallel test runner) produce one snapshot with 32 data files; no torn pending-index, no duplicate manifest entries.
- [ ] 16.6 [green] Confirm; refactor the brief `flock` window in `recordPending` if contention is observed.
- [ ] 16.7 [red] Functional test (traces): same as §16.1 for `POST /v1/traces`.
- [ ] 16.8 [red] Functional test (metrics): same as §16.1 for `POST /v1/metrics`.

## 17. Smoke test — DuckDB read-back

- [ ] 17.1 [red] Component test (gated on `which duckdb`): after committing 3 OTLP requests in a single snapshot, `duckdb -c "INSTALL iceberg; LOAD iceberg; SELECT count(*) FROM iceberg_scan('<table-root>');"` returns the expected row count.
- [ ] 17.2 [green] Document the smoke test in the README and `tests/Support/`.
- [ ] 17.3 [red] Component test: a `RowDelta` commit with one data file and one position-delete file is read back by DuckDB with the deleted row excluded.
- [ ] 17.4 [green] Confirm; this exercises both the `PositionDeleteWriter` and the v2 manifest delete handling.

## 18. Operator documentation

- [ ] 18.1 Update `README.md`: explain the new on-disk layout, the batched-commit visibility contract (200 = durable, snapshot visibility ≤ `BATCH_MAX_AGE_MS`), the env tunables, and the migration story (no automatic migration of existing Hive trees).
- [ ] 18.2 Document the `crashler:iceberg:flush` command and the recommended cron interval (60 s) for low-rate tenants.
- [ ] 18.3 Document the M1 limitations (no PHP-side reader, no schema evolution, no compaction, no expiry, no S3, single-host).
- [ ] 18.4 Document the operational warning: do not delete or move files inside an Iceberg table tree by hand.
- [ ] 18.5 Document the full library write surface (append, overwrite, row-delta) and call out that operators / library consumers can use `RowDelta` for GDPR-style row erasure.
- [ ] 18.6 Update DuckDB query recipes from `read_parquet(...)` to `iceberg_scan('<table-root>')`.

## 19. Spec-scenario coverage cross-check

- [ ] 19.1 For each `#### Scenario:` in `specs/iceberg-storage/spec.md`, confirm a test method has a `// spec: iceberg-storage/<requirement>/<scenario>` marker.
- [ ] 19.2 Same for the modified scenarios in `log-storage`, `trace-storage`, `metric-storage`.

## 20. Cross-cutting validation

- [ ] 20.1 `composer test` passes with zero deprecations/notices/warnings.
- [ ] 20.2 `composer iceberg:lint-boundary` passes with zero violations.
- [ ] 20.3 `composer test:coverage` meets thresholds; `App\Iceberg\*` ≥ 90% line coverage.
- [ ] 20.4 `openspec validate add-iceberg-table-writer --strict` passes.
- [ ] 20.5 Manual smoke test: send OTLP requests from `oha` against a dev instance under realistic load (1 000 req/s for 60 s); verify pending-index is bounded, snapshots accumulate at the expected rate, no torn manifests, DuckDB row count matches sent count.
