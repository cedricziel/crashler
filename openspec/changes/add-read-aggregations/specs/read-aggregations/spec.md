## ADDED Requirements

### Requirement: Aggregation endpoints under /v1/<signal>/aggregate

The system SHALL expose three aggregation endpoints alongside the GET search endpoints:

- `GET /v1/logs/aggregate`
- `GET /v1/traces/aggregate`
- `GET /v1/metrics/aggregate`

Each SHALL be declared as an API Platform `#[GetCollection]` operation with a custom processor (`App\Read\Http\Aggregate{Logs,Traces,Metrics}Processor`) and SHALL share the firewall, Bearer-token authentication, tenant scoping, time-window cap (default 30 days), and execution-timeout (default 10 seconds) of the GET search.

The endpoint SHALL accept the same documented filter parameters as the corresponding GET search (`since`, `until`, `service`, `environment`, `host`, `severityNumberMin`, `kind`, `metricName`, etc., and `attribute.<key>` per the multi-attribute cap), plus four aggregation-specific parameters:

- `function` — required. One of: `count`, `sum`, `avg`, `min`, `max`, `p50`, `p90`, `p95`, `p99`.
- `column` — required for every function except `count`. Names a typed numeric column (per signal allow-list, e.g. `httpResponseStatusCode` for traces, `valueDouble` / `valueInt` / `count` / `sum` for metrics, `severityNumber` for logs).
- `groupBy` — optional. Comma-separated list of typed columns from a per-signal allow-list. Multi-column group-by is supported.
- `interval` — optional. One of `1m`, `5m`, `15m`, `1h`, `1d`. Buckets the result by UTC-aligned time intervals.

#### Scenario: count without group-by returns one row
- **WHEN** `GET /v1/logs/aggregate?function=count&since=1h&service=checkout`
- **THEN** the response contains exactly one result row
- **AND** the row's `function` is `count` and `value` is the integer count of matching log rows
- **AND** the row's `group` object is empty `{}`

#### Scenario: count with group-by yields one row per distinct group
- **WHEN** `GET /v1/logs/aggregate?function=count&since=1h&groupBy=service`
- **THEN** the response contains one result row per distinct `service` in the matching rows
- **AND** each row's `group` is `{service: "<value>"}`

#### Scenario: p99 emits the t-digest quantile estimate
- **WHEN** `GET /v1/traces/aggregate?function=p99&column=httpResponseStatusCode&since=1h&groupBy=service`
- **THEN** every result row's `value` is a t-digest p99 estimate of `httpResponseStatusCode` for that service over the time window
- **AND** every row carries `sample_count` equal to the number of rows that fed the accumulator

#### Scenario: interval bucketing with multi-column group-by
- **WHEN** `GET /v1/traces/aggregate?function=count&since=2h&groupBy=service,kind&interval=15m`
- **THEN** the response contains rows grouped by `(service, kind, bucket_start_unix_nano)`
- **AND** every row carries `bucket_start_unix_nano` as a string (preserving int64 precision)
- **AND** empty `(service, kind, bucket)` combinations are absent from the response

#### Scenario: function param missing rejected
- **WHEN** the `function` parameter is absent
- **THEN** the response status is 400 with a message naming `function` as required and listing the supported functions

#### Scenario: column param missing for non-count function
- **WHEN** `function=p99` is set without a `column` parameter
- **THEN** the response status is 400 with a message that `column` is required for the requested function

#### Scenario: function out of allow-list
- **WHEN** `function=stddev` is requested
- **THEN** the response status is 400 with a message listing the supported functions

#### Scenario: groupBy on a JSON-backed column rejected
- **WHEN** `groupBy=attributesJson` is supplied
- **THEN** the response status is 400 with a message that group-by is only supported on typed columns and listing the per-signal allow-list

### Requirement: Cardinality and interval caps

A single aggregation request SHALL NOT exceed:

- `crashler.read.aggregate.max_groups` (default 200) — the count of distinct group-key combinations (excluding the time bucket)
- `crashler.read.aggregate.max_intervals` (default 720) — the count of time buckets when `interval` is set

When the response would exceed either cap, the system SHALL respond with HTTP 400 with a message naming the offending cap. The system SHALL NOT silently truncate.

#### Scenario: Cardinality cap exceeded
- **WHEN** an aggregation request groups by a column whose distinct values across the matching rows exceed 200
- **THEN** the response status is 400 with a message naming the `max_groups` cap and asking the operator to filter or reduce the group-by

#### Scenario: Interval cap exceeded
- **WHEN** an aggregation request's `since`/`until`/`interval` combination would produce more than 720 time buckets
- **THEN** the response status is 400 with a message naming the `max_intervals` cap

### Requirement: Aggregation result shape

The response body SHALL be a flat list of result rows. Each row SHALL carry:

- `group` — object with the group-by column values (empty when no `groupBy` is set)
- `bucket_start_unix_nano` — string (preserving int64 precision), present iff `interval` is set
- `function` — string echoing the requested function
- `column` — string, present iff function is not `count`
- `value` — number; integer for `count`, `min`/`max`/`sum` of integer columns; float for `avg` and percentiles
- `sample_count` — integer count of rows that fed the accumulator for this group/bucket

Rows SHALL be ordered first by the lex-ascending tuple of group-by column values, then by `bucket_start_unix_nano` ascending. The response SHALL NOT carry a cursor or pagination affordance.

The response SHALL carry a top-level `_links.search` affordance pointing back at the equivalent GET search URL (same filters, same time window) so clients can drill from an aggregate row to the underlying matching rows.

#### Scenario: Result row carries the documented fields
- **WHEN** an aggregation response is returned
- **THEN** every result row's keys are a subset of `{group, bucket_start_unix_nano, function, column, value, sample_count}`
- **AND** rows for `function=count` omit `column`
- **AND** rows for any other function include `column`

#### Scenario: Result list is sorted
- **WHEN** the response carries multiple rows
- **THEN** rows are sorted by `(group keys lex ascending, bucket_start_unix_nano ascending)`

#### Scenario: Aggregate response carries drill-down link
- **WHEN** any aggregation response is returned
- **THEN** the response top-level `_links.search` (rendered per the negotiated format) resolves to the corresponding GET search URL with the same filter parameters and the same `since`/`until`

### Requirement: Aggregation predicate evaluation reuses the existing scanner

The aggregation processor SHALL compile filter parameters into the same `App\Read\Compute\Predicates\*` classes used by the GET search. It SHALL NOT introduce a parallel filter compiler. The scanner SHALL apply Tier 0 partition pruning, Tier 1 row-group statistics push-down (where shipped), Tier 2 typed-column predicates, and Tier 4 JSON-attribute walks in the same order as the GET search.

The scanner's per-row work SHALL switch from "append to result list" to "feed accumulator", but every other behaviour (file ordering, file iteration, predicate AND-composition, time-window filtering, cursor irrelevance) SHALL be identical.

#### Scenario: Aggregation reuses the same predicate set as GET
- **WHEN** an aggregation request and a GET search request carry the same filter parameters and time window
- **THEN** the set of rows fed into the aggregation accumulators equals the set of rows the GET search would return ignoring `limit`

#### Scenario: Aggregation honours the execution-timeout
- **WHEN** the aggregation scan exceeds `crashler.read.execution_timeout_seconds`
- **THEN** the response status is 504 with the same message shape used by the GET search

### Requirement: Aggregations content-negotiate alongside other read endpoints

Aggregation endpoints SHALL support the same content negotiation as the GET search endpoints: `application/ld+json`, `application/hal+json`, `application/json`, `application/vnd.api+json`. The result-row shape SHALL render naturally into each format (Hydra `member`, HAL `_embedded`, compact array or wrapped object, JSON:API `data[]`).

#### Scenario: Hydra response has member array
- **WHEN** an aggregation request is made with `Accept: application/ld+json`
- **THEN** the response top level has `member` (or its Hydra equivalent) carrying the result rows

#### Scenario: HAL response has embedded array
- **WHEN** an aggregation request is made with `Accept: application/hal+json`
- **THEN** the response top level has `_embedded.<aggregateResources>` carrying the result rows
