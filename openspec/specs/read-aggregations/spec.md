# read-aggregations Specification

## Purpose
TBD - created by archiving change add-read-aggregations. Update Purpose after archive.
## Requirements
### Requirement: Aggregation endpoints under /v1/<signal>/aggregate

The system SHALL expose three aggregation endpoints alongside the GET search endpoints:

- `GET /v1/logs/aggregate`
- `GET /v1/traces/aggregate`
- `GET /v1/metrics/aggregate`

Each SHALL be exposed via a plain Symfony controller with `#[Route]` (mirroring the `ReadTraceController` precedent for non-collection-shaped responses under `/v1/`) and SHALL share the firewall, Bearer-token authentication, tenant scoping, time-window cap (default 30 days), and execution-timeout (default 10 seconds) of the GET search.

The endpoint SHALL accept a documented filter subset (`since`, `until`, `service`, `environment`, `host`, plus per-signal columns) plus aggregation parameters:

- `function` — required. One of: `count`, `sum`, `avg`, `min`, `max`. Percentile functions (`p50` / `p90` / `p95` / `p99`) are tracked as a follow-up.
- `column` — required for every function except `count`. Names a per-signal allow-listed numeric column (camelCase or snake_case both accepted via aliases).
- `groupBy` — optional. A single typed column from a per-signal allow-list. Multi-column groupBy is tracked as a follow-up; requests carrying a comma in `groupBy` SHALL return 400 in v1.
- `interval` — DEFERRED in v1. Requests supplying `interval` SHALL return HTTP 501 with a "not yet supported" message.

#### Scenario: count without group-by returns one row
- **WHEN** `GET /v1/logs/aggregate?function=count&since=1h&service=checkout`
- **THEN** the response contains exactly one result row
- **AND** the row's `function` is `count` and `value` is the integer count of matching log rows

#### Scenario: count with group-by yields one row per distinct group
- **WHEN** `GET /v1/logs/aggregate?function=count&since=1h&groupBy=service`
- **THEN** the response contains one result row per distinct `service` in the matching rows
- **AND** each row's `group` is `{resource_service_name: "<value>"}`

#### Scenario: sum on a numeric column
- **WHEN** `GET /v1/logs/aggregate?function=sum&column=severityNumber&since=1h&service=checkout`
- **THEN** every result row's `value` is the integer sum of `severity_number` for that group
- **AND** every row carries `sample_count` equal to the number of rows that fed the accumulator

#### Scenario: function param missing rejected
- **WHEN** the `function` parameter is absent
- **THEN** the response status is 400 with a message naming `function` as required and listing the supported functions

#### Scenario: column param missing for non-count function
- **WHEN** `function=sum` is set without a `column` parameter
- **THEN** the response status is 400 with a message that `column` is required for the requested function

#### Scenario: function out of allow-list
- **WHEN** `function=stddev` is requested
- **THEN** the response status is 400 with a message listing the supported functions

#### Scenario: groupBy on a JSON-backed column rejected
- **WHEN** `groupBy=attributesJson` is supplied
- **THEN** the response status is 400 with a message that group-by is only supported on typed columns and listing the per-signal allow-list

#### Scenario: interval bucketing not yet supported
- **WHEN** any aggregate request supplies an `interval` parameter
- **THEN** the response status is 501 with a "not yet supported in v1" message

#### Scenario: multi-column groupBy rejected in v1
- **WHEN** `groupBy=service,kind` is supplied
- **THEN** the response status is 400 with a message that multi-column group-by is not yet supported

### Requirement: Cardinality cap

A single aggregation request SHALL NOT exceed `crashler.read.aggregate.max_groups` (default 200) distinct group-key combinations. When the cap is exceeded, the system SHALL respond with HTTP 400 with a message naming the cap. The system SHALL NOT silently truncate.

#### Scenario: Cardinality cap exceeded
- **WHEN** an aggregation request groups by a column whose distinct values across the matching rows exceed 200
- **THEN** the response status is 400 with a message naming the `max_groups` cap and asking the operator to filter or reduce the group-by

### Requirement: Aggregation result shape

The response body SHALL be `application/json` with the following top-level fields:

- `function` — string echoing the requested function
- `column` — the resolved (snake_case) value column, or `null` for `count`
- `groupBy` — the resolved (snake_case) group-by column, or `null` if not grouped
- `window` — `{since_unix_nano, until_unix_nano}` strings (preserving int64 precision)
- `rows` — list of result entries

Each entry in `rows` SHALL carry:

- `group` — object with the resolved group-by column → value (or empty `{}` when not grouped)
- `function` — string echoing the requested function
- `column` — present iff function is not `count`
- `value` — number; integer for `count`/`sum`/`min`/`max` of integer columns, float for `avg`
- `sample_count` — integer count of rows that fed the accumulator for this group

Rows SHALL be ordered by group-key value ascending. The response SHALL NOT carry pagination affordances (the cardinality cap bounds the response size).

The `_links.search` drill-down affordance is tracked as a follow-up; v1 does not include it.

#### Scenario: Result row carries the documented fields
- **WHEN** an aggregation response is returned
- **THEN** every result row's keys are a subset of `{group, function, column, value, sample_count}`
- **AND** rows for `function=count` omit `column`
- **AND** rows for any other function include `column`

#### Scenario: Result list is sorted by group key
- **WHEN** the response carries multiple rows
- **THEN** rows are sorted by their group-key value lex-ascending

### Requirement: Aggregation reuses the existing scanner

The aggregation scanner (`App\Read\Compute\AggregatingScanner`) SHALL delegate row iteration to `App\Read\Compute\ParquetScanner` with `limit: PHP_INT_MAX`, applying the same predicates that the GET search would. Per-row work switches from "append to result list" to "feed accumulator". Predicate tier ordering, partition pruning, row-group push-down (where shipped), and execution-timeout enforcement are inherited unchanged.

#### Scenario: Aggregation reuses the same predicate set as GET
- **WHEN** an aggregation request and a GET search request carry the same filter parameters and time window
- **THEN** the set of rows fed into the aggregation accumulators equals the set of rows the GET search would return ignoring `limit`

#### Scenario: Aggregation honours the execution-timeout
- **WHEN** the aggregation scan exceeds `crashler.read.execution_timeout_seconds`
- **THEN** the response status is 504 with the same message shape used by the GET search

