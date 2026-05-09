## 1. Scaffolding

- [ ] 1.1 Add `int $groupsScanned` and `int $groupsSkipped` fields to `App\Read\Compute\ScanResult` with defaults of 0
- [ ] 1.2 Update `ParquetScanner` constructor / docstring to mention metadata-driven push-down
- [ ] 1.3 Add a private helper `canSkipRowGroup(RowGroup $group, list<Predicate> $predicates): bool` on `ParquetScanner`

## 2. Numeric predicate refutation

- [ ] 2.1 Add `tryGetNumericRange(): ?array{min: int|float, max: int|float}` (or equivalent) accessor on `ColumnInRange`, `ColumnGreaterEqual`, and numeric `ColumnEquals` so the scanner can introspect bounds without touching predicate internals
- [ ] 2.2 Implement min/max disjoint check in `canSkipRowGroup`:
  - `ColumnInRange(col, low, high)`: skip if `group.max(col) < low` OR `group.min(col) > high`
  - `ColumnGreaterEqual(col, v)`: skip if `group.max(col) < v`
  - `ColumnEquals(col, v)` numeric: skip if `v < group.min(col)` OR `v > group.max(col)`
- [ ] 2.3 When stats are null OR the column is absent from the row group's schema OR the predicate is non-numeric, return "indeterminate" and DO NOT skip
- [ ] 2.4 Iterate predicates: skip iff *any* refutes; otherwise scan

## 3. Wire push-down into the scan loop

- [ ] 3.1 In the per-file scan loop, call `metadata()->rowGroups()` once before opening data pages
- [ ] 3.2 For each row group: apply `canSkipRowGroup`; on skip increment `groupsSkipped`; on scan increment `groupsScanned`
- [ ] 3.3 Pass surviving row group offsets/indexes through to `Reader::read(...)` (via the appropriate flow-php API) so only the surviving groups are materialised
- [ ] 3.4 Verify counters pass through `ScanResult` to the calling state provider unchanged

## 4. Tests

- [ ] 4.1 Component test: write a multi-row-group fixture with `severity_number` values such that group A has max=9 and group B has max=20; run scanner with `severityNumberMin=17`; assert `groupsSkipped == 1`, `groupsScanned == 1`, and the response contains only group B's rows
- [ ] 4.2 Component test: time-window range push-down — fixture with two row groups whose `time_unix_nano` ranges fall before and inside the requested `since`/`until`; assert the early group is skipped
- [ ] 4.3 Unit test: predicate referencing a column not present in the row group's schema is treated as indeterminate (no skip), with a logs fixture missing the column
- [ ] 4.4 Unit test: row group whose statistics are null for the predicate column is scanned, not skipped
- [ ] 4.5 Unit test: query with only string predicates triggers zero row-group skips (other than the implicit time window)
- [ ] 4.6 Regression test: any existing read-API functional test runs against the new code path and produces bit-identical responses

## 5. Documentation

- [ ] 5.1 Add a "Performance" subsection to the read-API section of the project README listing which filters benefit from row-group push-down (numeric: `since`, `until`, `severityNumber`, `severityNumberMin`, `httpStatusCodeMin`, etc.) and which fall through (any string / JSON-attribute filter)
- [ ] 5.2 Note in README that `bodyContains` and `attribute.<key>` filters specifically do not push down — readers should combine them with at least one numeric predicate for selectivity
