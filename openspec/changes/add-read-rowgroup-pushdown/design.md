## Context

The shipped `read-api` capability promises tiered predicate evaluation: partition pruning (Tier 0) → row-group push-down (Tier 1) → typed-column scan (Tier 2) → JSON scan (Tier 4). Tier 0 and Tier 2/4 ship in v1. Tier 1 ships only as spec wording: `ParquetScanner` opens every row group regardless of whether its statistics could possibly satisfy the active predicates.

flow-php's `Reader::readData($file)->metadata()->rowGroups()` returns a `list<RowGroup>`, each carrying `columnsChunks()` whose `statistics()` expose `min`/`max`/`nullsCount`/`distinctCount` for the columns where the writer chose to record them. The default `ParquetFileWriter` config in this repo uses GZIP-compressed pages with statistics enabled — the metadata is already on disk for every file we have written.

The miss is purely on the read side. We carry six numeric predicates today (`time_unix_nano` lower/upper bound, `severity_number` exact + `>=`, `http_status_code_min`, `metric_value`-band) and at least three of them appear in nearly every realistic query (the time window is mandatory). A row group that covers `[2026-05-09T13:00, 2026-05-09T13:10]` for `time_unix_nano` cannot contribute rows to a query whose `until` is `2026-05-09T12:00`; the scanner must skip it without materialising pages.

Stakeholders: the read-API specs (`read-api`, `logs-query`, `traces-query`, `metrics-query`) all reference the perf claim; this change makes the spec text match the binary.

## Goals / Non-Goals

**Goals:**
- Implement the Tier 1 row-group skip path that the `read-api` spec already describes.
- Cover the three numeric predicate shapes that exist today: range, `>=`, and exact equality on a numeric column.
- Make push-down observable so component tests can assert it and so production logs/metrics can attribute scan-time savings.
- Keep the cost of evaluating the metadata bounded — opening metadata is cheap relative to a page read but still has a per-file syscall cost we should not multiply.

**Non-Goals:**
- Page-level (Tier 1.5) push-down using page indexes. flow-php exposes them but with rougher ergonomics; row-group granularity is the contract we promised.
- Bloom-filter push-down for string equality. Tracked separately if/when it shows up in profiling.
- New predicate shapes. This change makes the existing predicates faster, it does not add filters.
- Reordering predicates by selectivity. Tier order is the spec's contract; intra-tier reordering is a future concern.

## Decisions

### Decision 1: Read metadata once per file, before opening row groups
**Choice:** `ParquetScanner` calls `$reader->readData($file)` once, calls `metadata()->rowGroups()` once, evaluates skip-or-scan against each group, and only then iterates the surviving groups via the existing `$reader->read(...)` row stream filtered to the matching offsets.

**Alternative considered:** evaluate the skip check inside the row callback. Rejected — by the time a row is materialised we have already paid the page-read cost the optimisation is meant to avoid.

**Why:** the metadata block is at the tail of every Parquet file and flow-php caches it on the `ParquetFile` instance. The cost of "open metadata, decide, then open data pages for surviving groups" is dominated by the data-page cost we save. The check itself is a handful of integer comparisons per group per predicate — orders of magnitude cheaper than decompressing a single GZIP'd column chunk.

### Decision 2: Map only numeric predicates; everything else falls through
**Choice:** introduce a small visitor that, for each row group, attempts to refute it against `ColumnInRange`, `ColumnGreaterEqual`, and `ColumnEquals` (numeric). String predicates (`ColumnLikePrefix`, `ColumnLikeSuffix`, `JsonAttributeEquals`, `JsonStringContains`) and any predicate referencing a column without `min`/`max` statistics are treated as "indeterminate" — the group is not skipped on their account.

**Alternative considered:** cover string predicates too via lexicographic comparison of `min`/`max` byte ranges. Rejected for v1 — the column for `severity_text` and `resource_service_name` is dictionary-encoded; flow-php exposes `min`/`max` but the encoding nuances (nulls, empty strings, UTF-8 collation) are subtle. Numeric coverage hits the spec promise without the foot-guns.

**Why:** the proposal explicitly scopes the win to the numeric predicates the spec called out. The fall-through behaviour means no query gets slower; queries that have only string predicates simply scan the way they do today.

### Decision 3: A row group is skipped iff *any* predicate refutes it
**Choice:** evaluate each numeric predicate against the row group's per-column min/max. If any one of them refutes the group, skip. Otherwise keep.

**Why:** the predicates compose with logical AND in `ParquetScanner` (every active filter must hold). Refutation is therefore monotonic: a group that a single predicate refutes cannot satisfy the conjunction.

### Decision 4: `groupsSkipped` lives on `ScanResult`
**Choice:** extend `ScanResult` with `int $groupsSkipped` and `int $groupsScanned`. Default both to 0. The state providers carry them through unchanged; only tests and (eventually) the structured-log emitter read them.

**Alternative considered:** emit a counter via Symfony's `MeterRegistry` directly. Rejected — `ScanResult` is the seam every test already hits, and a registry call would couple compute to observability infrastructure for no extra value at this stage.

### Decision 5: Statistics-missing falls through to row-by-row evaluation
**Choice:** if `statistics()->min` or `->max` are null on the relevant column chunk for a numeric predicate, treat that predicate as "indeterminate" for the group. The group survives push-down; the row-by-row scan still applies the predicate per row.

**Why:** flow-php returns `null` for stats when the writer chose not to record them, when the column has no non-null values, or when the column type does not support stats. We must never skip a group that could contain matching rows.

## Risks / Trade-offs

- **Risk: column name mismatch between predicate and Parquet schema.** Predicates carry the Parquet column name (snake_case) already; `ParquetScanner::canSkipRowGroup` looks up the column chunk by that exact name. → Mitigation: a unit test asserts that a predicate referencing a column not present in the row group's schema is treated as indeterminate (no skip), preventing accidental refutation when the writer evolves the schema.

- **Risk: integer overflow / type coercion on `time_unix_nano`.** The column is INT64; predicate values are PHP integers but at the upper end of the range can hit 19 digits. → Mitigation: comparisons use PHP's native `<` / `>` on integers; no casting to float. Add a test with a row group whose `max` is exactly `PHP_INT_MAX` minus a small epsilon and verify behaviour.

- **Risk: spec drift between `read-api` and the per-signal specs.** The Tier 1 wording lives in `read-api`; the per-signal specs reference it. → Mitigation: this change only modifies `read-api`; per-signal specs continue to inherit by reference.

- **Trade-off: complexity vs. selectivity.** A query with no numeric predicates pays a tiny cost (read metadata, decide nothing can be skipped). On non-time-windowed queries this is wasted work, but every Crashler query carries `since`/`until` bounds via the state providers, so in practice the time-window predicate guarantees we always have at least one numeric predicate to evaluate.

- **Trade-off: in-memory metadata cache.** flow-php's `ParquetFile` instance caches metadata on first access. If the scanner ever held many `ParquetFile` instances open simultaneously this would matter; today the scanner closes each file before opening the next, so cache lifetime is bounded by the loop body.

## Migration Plan

- No schema or storage migration. Files written before and after this change are identical on disk.
- No config flags. The optimisation is unconditional.
- Roll-forward: deploy normally. The new `groupsSkipped` field on `ScanResult` is additive.
- Rollback: revert. Behaviour returns to v1 (slower but correct).

## Open Questions

- Should we surface `groupsSkipped` / `groupsScanned` in the response body for debugging? Default: no — query-debug fields belong behind a feature flag we have not committed to. Tests assert via the in-process `ScanResult`.
- Should we expose a way for ingest-side compaction to record histograms in row-group statistics? Out of scope; flow-php's writer records `min`/`max` already, which is what the read path uses.
