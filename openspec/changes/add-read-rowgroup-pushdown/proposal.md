## Why

The shipped read API spec promises Tier 1 push-down ("Where flow-php exposes per-row-group min/max statistics for typed columns, the scanner SHALL skip row groups whose statistics disjoint from the predicate"). The v1 implementation streams every row group; the spec promise is unfulfilled. On busy partitions with selective numeric predicates (`severityNumberMin=17`, `httpStatusCodeMin=500`), this leaves a real performance win on the table — easily 10–100× depending on selectivity.

This change makes the spec promise real: read row-group metadata via flow-php's `Reader`, evaluate cheap min/max checks against the numeric predicates, and skip the matching groups before reading any data pages.

## What Changes

- `App\Read\Compute\ParquetScanner` now reads per-row-group statistics from each Parquet file's metadata via flow-php's `ParquetFile::metadata()->rowGroups()`.
- For each row group, evaluate the active numeric predicates against the column's min/max statistics:
  - `ColumnInRange('time_unix_nano', low, high)` → skip if `[group.min, group.max]` disjoints from `[low, high]`
  - `ColumnGreaterEqual(col, v)` → skip if `group.max < v`
  - `ColumnEquals(col, v)` (numeric) → skip if `v < group.min` or `v > group.max`
- Row groups whose statistics are missing or whose column type doesn't support stats (e.g., JSON-string columns) fall through to row-by-row evaluation — current behavior unchanged.
- New scanner counter: `groupsSkipped` exposed on `ScanResult` so component tests can assert push-down actually happened.
- README "Reading data" section adds a brief "Performance" subsection documenting which filters benefit from push-down.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `read-api`: tightens the "Compute via streaming flow-php Parquet scanner" requirement with concrete scenarios for row-group push-down and a delta on `ScanResult` for observability.

## Impact

- New code: numeric-predicate ↔ row-group statistic comparison logic in `ParquetScanner`. ~80 lines.
- Tests: a new component test that constructs a multi-row-group fixture, invokes the scanner with `ColumnGreaterEqual('severity_number', 17)`, and asserts only the row groups whose `max(severity_number) >= 17` were materialised.
- No config changes. No new dependencies. flow-php's `Reader` already exposes the metadata.
- No backward-incompatible changes. Behavior for queries that already reach all matching rows is bit-identical; queries with selective numeric predicates simply complete faster.
- Production deploy: additive perf optimization. No env flags.
