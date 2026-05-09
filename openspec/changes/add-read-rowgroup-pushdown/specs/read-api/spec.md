## MODIFIED Requirements

### Requirement: Compute via streaming flow-php Parquet scanner

The system SHALL execute read queries via a streaming `App\Read\Compute\ParquetScanner` that reads Parquet files row-by-row using flow-php's `Reader`. Files SHALL be iterated in ULID order (creation-time order). Filters SHALL be evaluated as typed predicates in tier order (cheap top-level columns before expensive JSON-string scans) so wide queries fail-fast on the cheap predicates.

For each Parquet file, BEFORE iterating rows the scanner SHALL read the file's row-group metadata via flow-php's `ParquetFile::metadata()->rowGroups()` and, for every active numeric predicate (`ColumnInRange`, `ColumnGreaterEqual`, or numeric `ColumnEquals`), evaluate the predicate against the per-row-group `min`/`max` statistics of the referenced column. A row group SHALL be skipped — not opened for row iteration — when at least one numeric predicate refutes it (i.e. its `[min, max]` interval is disjoint from the predicate's accepted range). When statistics are absent, when the column type does not carry stats, or when the predicate references a column not present in the row group's schema, the row group SHALL fall through to row-by-row evaluation. String predicates (prefix, suffix, JSON substring, JSON-attribute equals) and any other non-numeric predicate SHALL NOT be applied at the row-group skip step in v1.

The scanner SHALL expose, on the `ScanResult` value object returned to state providers, two integer counters: `groupsScanned` (row groups whose data pages were materialised) and `groupsSkipped` (row groups elided by the metadata check). These fields SHALL be observable by component tests and SHALL NOT be surfaced in the HTTP response body.

Per-request execution time SHALL be bounded by `crashler.read.execution_timeout_seconds` (default 10); when exceeded, the system SHALL respond with HTTP 504 and a message asking the client to narrow the time window or filters.

There SHALL NOT be a configurable choice of compute engine; the scanner is the only execution path in v1. (A future change may introduce alternatives behind a `ScansParquet` interface — that is not a v1 contract.)

#### Scenario: Scanner emits matching rows in ULID order
- **WHEN** a search request resolves to a partition with three Parquet files written at distinct times
- **THEN** the scanner reads them in ULID-ascending order
- **AND** the response's row collection reflects that order (older first within a partition)

#### Scenario: Scanner stops early when limit reached
- **WHEN** a partition contains 10 000 matching rows and `limit=100`
- **THEN** the scanner reads at most enough row groups to surface 100 rows
- **AND** does not continue scanning the rest of the partition

#### Scenario: Tier-ordered predicate evaluation short-circuits
- **WHEN** a request carries `service=checkout` AND `attribute.exception.type=Boom`
- **AND** a row's `resourceServiceName` is `payments` (mismatching `service`)
- **THEN** that row is rejected by the cheap `ColumnEquals('resource_service_name', ...)` predicate
- **AND** the expensive `JsonAttributeEquals` on `attributes_json` is NOT evaluated for that row

#### Scenario: Row group skipped via min/max push-down for `>=` predicate
- **WHEN** a partition's row group reports `max(severity_number) = 9` in its statistics and the request carries `severityNumberMin=17`
- **THEN** the scanner skips that row group entirely
- **AND** does not iterate rows inside it
- **AND** the returned `ScanResult.groupsSkipped` is incremented by one for that group

#### Scenario: Row group skipped via min/max push-down for range predicate
- **WHEN** a partition's row group reports `[min, max]` for `time_unix_nano` that falls entirely before the request's `since` lower bound
- **THEN** the scanner skips the row group
- **AND** `groupsSkipped` reflects the skip

#### Scenario: Row group scanned when statistics are missing
- **WHEN** a partition's row group reports null `min`/`max` for the predicate's column (writer did not record statistics, or column type does not support them)
- **THEN** the scanner falls through and evaluates the predicate row-by-row inside that group
- **AND** `groupsScanned` is incremented for that group
- **AND** the response is bit-identical to a scan that did not attempt push-down

#### Scenario: String predicates do not trigger push-down
- **WHEN** a request carries only a `service=checkout` filter and no numeric predicates beyond the time window
- **THEN** the scanner SHALL NOT skip any row group on account of the string predicate
- **AND** any row groups elided are elided only by the time-window numeric predicate

#### Scenario: Execution timeout returns 504
- **WHEN** a request takes longer than `crashler.read.execution_timeout_seconds` to materialise the response
- **THEN** the system responds with HTTP 504
- **AND** the message asks the client to narrow filters or reduce the time window
