# read-api-quality Specification

## Purpose
TBD - created by archiving change add-read-test-coverage-roundup. Update Purpose after archive.
## Requirements
### Requirement: Regression-test coverage for load-bearing v1 read-API invariants

The system SHALL maintain dedicated automated tests for each of the following read-API invariants. The tests are not gates on behaviour (each invariant is normative under its own capability spec); they exist so a future refactor that silently breaks the property fails CI rather than ships.

The following invariants SHALL each be covered by at least one named test:

#### Scenario: Time-window row-group push-down skips out-of-window groups
- **WHEN** the test fixture writes three Parquet files into one partition with `time_unix_nano` ranges that fall entirely-before / inside / entirely-after the request's `[since, until]`
- **AND** the scanner is invoked with that window
- **THEN** the test asserts `groupsSkipped == 2` and `groupsScanned == 1`
- **AND** the returned rows come exclusively from the inside-window file

#### Scenario: Schema-absent column treated as indeterminate
- **WHEN** the test runs the row-group skipper with a numeric predicate referencing a column NOT present in the row group's schema
- **THEN** the skipper returns false (no skip) and the row group is scanned

#### Scenario: Multi-attribute filter composition on traces
- **WHEN** the test writes a 3-span fixture covering the {neither, one, both} matrix for two attribute keys
- **AND** the request carries both `attribute.<k1>=v1` and `attribute.<k2>=v2`
- **THEN** only the span matching both attributes is returned

#### Scenario: Multi-attribute filter composition on metrics
- **WHEN** the test writes a 3-row metric fixture covering the {neither, one, both} matrix for two attribute keys
- **AND** the request carries both `attribute.<k1>=v1` and `attribute.<k2>=v2`
- **THEN** only the row matching both attributes is returned

#### Scenario: POST search rejects oversize body with 413
- **WHEN** the test invokes `PostSearchRequestParser` with a synthetic Request carrying a body larger than `crashler.read.post_search.max_body_bytes`
- **THEN** the parser raises `InvalidPostSearchBodyException` whose `statusCode` is 413
- **AND** the message names the configured cap

#### Scenario: Aggregation cardinality cap rejects over-cap requests
- **WHEN** the test writes >200 distinct group-key fixtures into a partition
- **AND** the aggregate endpoint is invoked with `groupBy=service` (or equivalent)
- **THEN** the response is HTTP 400 with a message naming the `crashler.read.aggregate.max_groups` cap

