**Methodology.** Every section below is TDD: for each implementation task, a failing test is written first, observed to fail for the expected reason, then minimum production code is written to make it green, then refactored with tests green. Tasks marked `[red]` write a failing test; tasks marked `[green]` add the implementation that makes the most recently red test pass.

## 1. Project setup and dependencies

- [ ] 1.1 Add production dep `wikimedia/avro` (or current maintained fork). Run `composer require`.
- [ ] 1.2 Add env vars to `.env` and `.env.test`:
  - `CRASHLER_TABLE_FORMAT=hive` (default)
  - `CRASHLER_ICEBERG_COMMIT_RETRIES=5`
  - `CRASHLER_ICEBERG_LOCK_TIMEOUT_MS=2000`
- [ ] 1.3 Bind env vars to container parameters in `config/services.yaml`: `crashler.table_format`, `crashler.iceberg.commit_retries`, `crashler.iceberg.lock_timeout_ms`.
- [ ] 1.4 Update README "Optional PHP extensions" section: no new extensions required; flag DuckDB ≥ 0.10 with the `iceberg` extension as the recommended reader.

## 2. Iceberg spike — validate Avro encoding upstream

- [ ] 2.1 [red] Write a one-shot script (`tests/Iceberg/Spike/manifest_roundtrip_test.php`) that builds a minimal Iceberg manifest record via `wikimedia/avro` against the published v2 manifest schema, writes it to disk, and re-reads it via Python (`pip install pyiceberg`) in a sub-shell. Assert byte-level round-trip and field equality.
- [ ] 2.2 [green] If §2.1 passes, mark the dependency choice confirmed and remove the spike script.
- [ ] 2.3 [red] If §2.1 fails on a specific Avro feature (logical types, unions, defaults), document the failure mode in `design.md` under "D6 Fallback" and proceed to §2.4.
- [ ] 2.4 [green] (only if §2.3 triggers) Implement a 200-LoC hand-rolled Avro encoder targeted at exactly the manifest and manifest-list schemas; pass §2.1's round-trip test against the new encoder.

## 3. iceberg-storage — Schema value object (TDD)

- [ ] 3.1 [red] Unit test: `App\Iceberg\Metadata\Schema` constructed from the `logs/v1` schema-catalog YAML produces an Iceberg-shaped schema with `schema-id: 0` and one field per documented column with the correct Iceberg type mapping (`int32` → `int`, `int64` → `long`, `string` → `string`).
- [ ] 3.2 [green] Implement `Schema::fromCatalogSchema(SchemaDefinition $def): self` and `toArray(): array`.
- [ ] 3.3 [red] Test: required vs optional repetition translates to `required: true|false` on each field.
- [ ] 3.4 [red] Test: every field receives a stable, monotonic `field-id` starting at 1; ordering matches the catalog YAML.
- [ ] 3.5 [green] Add `field-id` assignment.

## 4. iceberg-storage — PartitionSpec value object (TDD)

- [ ] 4.1 [red] Unit test: a `PartitionSpec` for the logs table with identity transforms on `date` and `hour` serializes to the documented v2 JSON shape with `spec-id: 0` and two fields whose `source-id` references the corresponding schema field-ids.
- [ ] 4.2 [green] Implement `PartitionSpec` value object and `toArray()`.
- [ ] 4.3 [red] Test: serialization is stable across calls (no nondeterministic ordering).

## 5. iceberg-storage — TableMetadata value object and writer (TDD)

- [ ] 5.1 [red] Unit test: a freshly initialized `TableMetadata` for an empty table has `format-version: 2`, `last-sequence-number: 0`, `current-snapshot-id: null`, an empty `snapshots` array, and a single-element `schemas`/`partition-specs`.
- [ ] 5.2 [green] Implement `TableMetadata::initial(string $tableUuid, string $location, Schema $s, PartitionSpec $p): self`.
- [ ] 5.3 [red] Test: `TableMetadataWriter::write($metadata, $stream)` produces JSON that round-trips through `json_decode` to an equal array.
- [ ] 5.4 [green] Implement the writer (no pretty-printing; stable key order).
- [ ] 5.5 [red] Test: appending a snapshot increments `last-sequence-number`, sets `current-snapshot-id`, and prepends the previous snapshot id to `snapshot-log`.
- [ ] 5.6 [green] Implement `TableMetadata::withAppendedSnapshot(Snapshot $s): self`.

## 6. iceberg-storage — Manifest writer (TDD, Avro)

- [ ] 6.1 [red] Component test: `ManifestWriter::write($manifest, $tmpPath)` produces an Avro file that PyIceberg's `ManifestFile.read` parses without error and returns the same data-file entries.
- [ ] 6.2 [green] Implement `ManifestWriter` against the v2 Avro schema, populating `data_file.file_format = 'PARQUET'`, `data_file.partition`, `data_file.record_count`, `data_file.file_size_in_bytes`, lower/upper bounds for partition columns and `time_unix_nano` only.
- [ ] 6.3 [red] Component test: a manifest with two data files round-trips with both entries in declared order.
- [ ] 6.4 [red] Component test: `data_file.column_sizes` and `data_file.value_counts` are populated from the Parquet writer's row-group metadata when available; absent otherwise (no error).
- [ ] 6.5 [green] Add metadata extraction from the Parquet writer's footer.

## 7. iceberg-storage — ManifestList writer (TDD, Avro)

- [ ] 7.1 [red] Component test: `ManifestListWriter::write($list, $tmpPath)` produces an Avro file that PyIceberg's `Snapshot._manifests` reads back with the expected `manifest_path`, `added_data_files_count`, and `partition_summaries` (empty array allowed).
- [ ] 7.2 [green] Implement `ManifestListWriter` against the v2 Avro schema.
- [ ] 7.3 [red] Component test: appending a new manifest to an existing list preserves the prior entries in order.
- [ ] 7.4 [green] Add `ManifestList::withAppendedManifest()`.

## 8. iceberg-storage — FilesystemCatalog (TDD)

- [ ] 8.1 [red] Component test using `TempStorageRoot`: `FilesystemCatalog::initialize(string $tableRoot, Schema $s, PartitionSpec $p)` creates `metadata/v1.metadata.json` and `metadata/version-hint.text` containing `1`.
- [ ] 8.2 [green] Implement `FilesystemCatalog::initialize`.
- [ ] 8.3 [red] Test: calling `initialize` on an existing table is a no-op (idempotent) and does not bump the version.
- [ ] 8.4 [green] Add idempotency.
- [ ] 8.5 [red] Test: `currentVersion()` reads `version-hint.text` and returns the integer.
- [ ] 8.6 [red] Test: `loadCurrentMetadata()` returns the `TableMetadata` parsed from `v<N>.metadata.json` where N = currentVersion.
- [ ] 8.7 [green] Implement both readers.
- [ ] 8.8 [red] Test: `commitNextMetadata(TableMetadata $next, int $expectedPriorVersion)` writes `v(N+1).metadata.json.tmp`, fsyncs, renames, then atomically replaces `version-hint.text`.
- [ ] 8.9 [green] Implement the commit primitive (no concurrency control yet — that's §9).
- [ ] 8.10 [red] Test: `commitNextMetadata` with mismatched `expectedPriorVersion` throws `ConcurrentCommitException` and leaves no `.tmp` files behind.
- [ ] 8.11 [green] Add the version check.

## 9. iceberg-storage — CommitCoordinator (TDD)

- [ ] 9.1 [red] Component test: `CommitCoordinator::commit($tableRoot, callable $build)` acquires an exclusive `flock` on `metadata/.commit.lock`, invokes the build callback with the current `TableMetadata`, and commits the returned next-metadata via `FilesystemCatalog`.
- [ ] 9.2 [green] Implement single-attempt commit with `flock` + `flock(LOCK_UN)`.
- [ ] 9.3 [red] Component test: when `flock` would block, the coordinator waits up to `lock_timeout_ms` and then throws `LockTimeoutException`.
- [ ] 9.4 [green] Add timeout (use `flock(LOCK_EX|LOCK_NB)` with a deadline loop and short sleeps).
- [ ] 9.5 [red] Component test: when `commitNextMetadata` throws `ConcurrentCommitException`, the coordinator releases the lock, re-reads the latest metadata, re-invokes the build callback, and retries up to `commit_retries` times.
- [ ] 9.6 [green] Add the retry loop.
- [ ] 9.7 [red] Component test: build callback throwing a non-conflict exception aborts the commit immediately and bubbles the exception; no `.tmp` files remain.
- [ ] 9.8 [green] Wire the cleanup path.
- [ ] 9.9 [red] Component test (concurrency simulation): two coroutines/forks racing on the same table both succeed and produce two distinct snapshots in deterministic order; `version-hint.text` ends at `3` (initial `1` + two appends).
- [ ] 9.10 [green] Confirm; refactor only if the simulation reveals a bug.

## 10. iceberg-storage — IcebergTableWriter facade (TDD)

- [ ] 10.1 [red] Component test: `IcebergTableWriter::writeAndCommit($rows, $partitionValues, $tenant, $signal)` writes a Parquet file under `<table-root>/data/date=…/hour=…/`, then commits a snapshot referencing it; reading the table via `iceberg_scan` from a DuckDB sub-shell returns the same rows.
- [ ] 10.2 [green] Implement the facade: delegate Parquet writing to the existing `ParquetFileWriter`, then build a manifest entry from the resulting file's stats and call `CommitCoordinator::commit`.
- [ ] 10.3 [red] Component test: writer failure between the Parquet write and the commit cleans up the orphan Parquet file.
- [ ] 10.4 [green] Add try/cleanup around the commit.
- [ ] 10.5 [red] Component test: first request to a never-seen-before table auto-initializes the table (calls `FilesystemCatalog::initialize`) before the first commit.
- [ ] 10.6 [green] Add lazy initialization.

## 11. log-storage / trace-storage / metric-storage — opt-in switch (TDD)

- [ ] 11.1 [red] Unit test: with `CRASHLER_TABLE_FORMAT=hive` (default), the `LogsIngestService` resolves the legacy `ParquetFileWriter` and writes Hive-style files (existing behavior; regression guard).
- [ ] 11.2 [green] Verify existing wiring; add a service factory that picks the writer based on the parameter.
- [ ] 11.3 [red] Functional test: with `CRASHLER_TABLE_FORMAT=iceberg`, `POST /v1/logs` returns 200 and writes the Parquet file under `<APP_SHARE_DIR>/iceberg/logs/<tenant>/data/…`, with a new snapshot in `metadata/v2.metadata.json`.
- [ ] 11.4 [green] Wire the Iceberg path.
- [ ] 11.5 Mirror §11.3–§11.4 for traces and metrics.
- [ ] 11.6 [red] Functional test: `CRASHLER_TABLE_FORMAT=invalid` fails fast at boot with a clear error.
- [ ] 11.7 [green] Add the validation.

## 12. Smoke test — DuckDB read-back

- [ ] 12.1 [red] Component test (gated on `which duckdb`, skipped otherwise): after writing 3 OTLP requests in Iceberg mode, running `duckdb -c "INSTALL iceberg; LOAD iceberg; SELECT count(*) FROM iceberg_scan('<table-root>');"` returns the expected row count.
- [ ] 12.2 [green] Document the smoke test in the README and in `tests/Support/` for operator reproduction.

## 13. Operator documentation

- [ ] 13.1 Update `README.md`: explain `CRASHLER_TABLE_FORMAT`, the Iceberg on-disk layout, and a DuckDB reader recipe.
- [ ] 13.2 Document the v1 limitations (one snapshot per request, no compaction, no expiry, no S3, single-host).
- [ ] 13.3 Document the operational warning: do not delete or move files inside an Iceberg table tree by hand; the metadata will become inconsistent.
- [ ] 13.4 Document the migration story: tenants are not auto-migrated between `hive` and `iceberg`; a future change will provide a backfill tool.

## 14. Spec-scenario coverage cross-check

- [ ] 14.1 For each `#### Scenario:` in `specs/iceberg-storage/spec.md`, confirm a test method has a `// spec: iceberg-storage/<requirement>/<scenario>` marker.
- [ ] 14.2 For the modified scenarios in `log-storage`, `trace-storage`, `metric-storage`, confirm the same.

## 15. Cross-cutting validation

- [ ] 15.1 `composer test` passes in both modes (`CRASHLER_TABLE_FORMAT=hive` and `CRASHLER_TABLE_FORMAT=iceberg`) with zero deprecations/notices/warnings.
- [ ] 15.2 `composer test:coverage` meets thresholds; `App\Iceberg\*` ≥ 90% line coverage.
- [ ] 15.3 `openspec validate add-iceberg-table-writer --strict` passes.
- [ ] 15.4 Manual smoke test: run §12.1 against a real PHP-FPM + nginx dev instance with three concurrent `oha` workers hammering `/v1/logs`; assert no torn snapshots and `duckdb` row count matches sent count.
