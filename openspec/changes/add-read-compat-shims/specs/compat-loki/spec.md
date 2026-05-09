## ADDED Requirements

### Requirement: Loki shim — pinned to Loki 2.9.x

The system SHALL expose a Loki-compatible shim under `/compat/loki/api/v1/` when `crashler.compat.loki.enabled` is `true`. The shim SHALL pin to Loki 2.9.x. Endpoints in v1:

- `GET /compat/loki/api/v1/labels`
- `GET /compat/loki/api/v1/label/{name}/values`
- `GET /compat/loki/api/v1/query_range`

All endpoints SHALL return `application/json` and SHALL share `read-api`'s Bearer auth, tenant scoping, and execution timeout. Responses SHALL match the shape Grafana's Loki data source expects from a Loki 2.9.x server enough to satisfy "Test connection", "Browse labels", and "Run a basic query" workflows.

#### Scenario: Pinned version is Loki 2.9.x
- **WHEN** an operator inspects this shim's specification or its README section
- **THEN** the pinned upstream version is named as Loki 2.9.x

#### Scenario: All Loki endpoints return application/json
- **WHEN** any documented Loki shim endpoint returns a 2xx response
- **THEN** the `Content-Type` header is `application/json`

### Requirement: Loki labels enumeration

`GET /compat/loki/api/v1/labels` SHALL return:

```json
{"status": "success", "data": ["service", "environment", "host", "severityText", "severityNumber"]}
```

The list SHALL be a closed set of label names exposed by the shim — exactly the labels accepted in `GET /compat/loki/api/v1/query_range`'s selector. The list SHALL NOT include attribute keys (those are unbounded; Loki's `/labels` is finite).

`GET /compat/loki/api/v1/label/{name}/values` SHALL return distinct values for the named label that appear in the partition root for the requesting tenant within the time window (default 1h, capped to `crashler.read.max_time_window_days`):

```json
{"status": "success", "data": ["checkout", "payments", "billing"]}
```

When the named label is not in the closed list, the response SHALL be HTTP 400 with a message naming the unsupported label.

#### Scenario: Labels endpoint returns the closed list
- **WHEN** `GET /compat/loki/api/v1/labels` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** the body's `data` is a JSON array containing exactly the labels documented above

#### Scenario: Label values endpoint enumerates the partition
- **WHEN** `GET /compat/loki/api/v1/label/service/values?start=...&end=...` arrives with a valid bearer
- **THEN** the response is `{status: "success", data: [...]}` listing the distinct service names found in matching partitions
- **AND** values from other tenants are NOT included

#### Scenario: Unsupported label name rejected
- **WHEN** `GET /compat/loki/api/v1/label/banana/values` arrives
- **THEN** the response status is 400 with a message naming `banana` as unsupported and listing the supported labels

### Requirement: Loki query_range — selector + line filter

`GET /compat/loki/api/v1/query_range` SHALL accept:

- `query` — a LogQL string of shape `{<selector>} [ |= "<substring>" ]`. The `<selector>` is a comma-separated list of `key="value"` equality predicates. The optional ` |= "<substring>" ` line filter compiles to `JsonStringContains('body_json', "<substring>")`.
- `start`, `end` — RFC3339 strings or unix-nano numeric strings; mapped to `since`/`until`.
- `step` — Loki's downsampling hint. The shim SHALL respect `step` only as a sanity check (e.g. reject `step=` of less than 1s).
- `limit` — capped at `crashler.read.max_page_size`.
- `direction` — `forward` (default) or `backward`. Backward returns rows in descending `timeUnixNano` order.

The response SHALL be:

```json
{"status": "success", "data": {
  "resultType": "streams",
  "result": [
    {
      "stream": {"service": "checkout", "severityText": "ERROR"},
      "values": [["1714824000123456789", "<rendered log line>"], ...]
    }
  ]
}}
```

A "stream" SHALL be a distinct combination of label values from the selector. The `values` array entries SHALL be `[unix_nano_string, line]` tuples. The line SHALL be the row's `bodyJson` rendered as a string.

#### Scenario: Selector compiles to typed predicates
- **WHEN** `GET /compat/loki/api/v1/query_range?query={service="checkout",severityText="ERROR"}&start=...&end=...`
- **THEN** the underlying scan applies `ColumnEquals('resource_service_name', 'checkout')` AND `ColumnEquals('severity_text', 'ERROR')`
- **AND** the response groups matching rows by the `(service, severityText)` tuple into one or more streams

#### Scenario: Line filter compiles to body substring
- **WHEN** the LogQL query contains a `|= "panic"` line filter
- **THEN** the underlying scan applies `JsonStringContains('body_json', 'panic')` in addition to the selector predicates

#### Scenario: Direction backward returns descending order
- **WHEN** the request carries `direction=backward`
- **THEN** every stream's `values` array is sorted by timestamp descending

### Requirement: Loki shim non-preservations

The Loki shim SHALL NOT support:

- Regex selectors (`=~`, `!~`). Requests carrying these in the LogQL query SHALL return HTTP 400 with a message naming regex selectors as unsupported.
- Range vectors and aggregation operators (`rate()`, `count_over_time()`, `sum_over_time()`). Aggregations belong on the Crashler `/v1/<signal>/aggregate` endpoint family.
- Label filter expressions inside the query (` | label="value"`).
- Format expressions (`| line_format`, `| label_format`).
- The `limit` parameter exceeding `crashler.read.max_page_size`.

#### Scenario: Regex selector rejected
- **WHEN** `GET /compat/loki/api/v1/query_range?query={service=~".*checkout.*"}&...`
- **THEN** the response status is 400 with a message that regex selectors are unsupported and naming the supported equality-only selector form

#### Scenario: Aggregation operator rejected
- **WHEN** `GET /compat/loki/api/v1/query_range?query=count_over_time({service="checkout"}[5m])&...`
- **THEN** the response status is 400 with a message that LogQL aggregations are unsupported and pointing at `/v1/logs/aggregate` instead
