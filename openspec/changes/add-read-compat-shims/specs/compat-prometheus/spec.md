## ADDED Requirements

### Requirement: Prometheus shim — pinned to Prometheus 2.x

The system SHALL expose a Prometheus-compatible shim under `/compat/prom/api/v1/` when `crashler.compat.prometheus.enabled` is `true`. The shim SHALL pin to Prometheus 2.x's HTTP API. Endpoints in v1:

- `GET /compat/prom/api/v1/labels`
- `GET /compat/prom/api/v1/label/{name}/values`
- `GET /compat/prom/api/v1/query_range`

All endpoints SHALL return `application/json` and SHALL share `read-api`'s Bearer auth, tenant scoping, and execution timeout. Responses SHALL match the shape Grafana's Prometheus data source expects from a Prometheus 2.x server enough to satisfy "Test connection", "Browse labels", and a documented PromQL subset.

#### Scenario: Pinned version is Prometheus 2.x
- **WHEN** an operator inspects this shim's specification or its README section
- **THEN** the pinned upstream version is named as Prometheus 2.x

#### Scenario: All Prometheus endpoints return application/json
- **WHEN** any documented Prometheus shim endpoint returns a 2xx response
- **THEN** the `Content-Type` header is `application/json`

### Requirement: Prometheus labels enumeration

`GET /compat/prom/api/v1/labels` SHALL return:

```json
{"status": "success", "data": ["service", "environment", "host", "metricName", "metricType", "aggregationTemporality"]}
```

`GET /compat/prom/api/v1/label/{name}/values` SHALL return the distinct values for the named label across the metrics partitions for the requesting tenant within the time window. The response shape is `{"status": "success", "data": ["..."]}`.

When the named label is not in the closed list, the response SHALL be HTTP 400 with a message naming the unsupported label.

#### Scenario: Labels endpoint returns the closed list
- **WHEN** `GET /compat/prom/api/v1/labels` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** the body's `data` array equals the documented closed list

### Requirement: Prometheus query_range — count_over_time and sum-by

`GET /compat/prom/api/v1/query_range` SHALL accept:

- `query` — a PromQL string in one of three documented forms:
  - `<selector>` — raw point selection. `<selector>` is a comma-separated list of `key="value"` equality predicates inside `{...}`. Returns time-series points for matching metrics.
  - `count_over_time(<selector>[<range>])` — count of matching rows per `<range>` bucket. `<range>` is a duration like `1m`, `5m`, `1h`.
  - `sum by (<label_list>) (<selector>)` — group-by sum on `valueDouble` (when the metric type is GAUGE/SUM). `<label_list>` is a comma-separated list of typed-column labels.
- `start`, `end` — unix-second floats; mapped to `since`/`until` unix-nano.
- `step` — duration for buckets when no `[range]` is in the query.

The response SHALL be:

```json
{"status": "success", "data": {
  "resultType": "matrix",
  "result": [
    {
      "metric": {"service": "checkout", "metricName": "..."},
      "values": [[<unix_seconds_float>, "<value_string>"], ...]
    }
  ]
}}
```

The compute SHALL delegate to the existing aggregation primitives where applicable (the `count_over_time` and `sum by` forms map cleanly to `add-read-aggregations`'s `count` and `sum` functions). Raw selectors map to a typed-column scan and the `metric` object carries the selector's labels plus the `metricName`.

#### Scenario: count_over_time delegates to aggregation
- **WHEN** `GET /compat/prom/api/v1/query_range?query=count_over_time({metricName="http.server.request.duration"}[1m])&start=...&end=...&step=60`
- **THEN** the underlying scan invokes the existing aggregation primitives with `function=count`, `interval=1m`, and the predicate `ColumnEquals('metric_name', 'http.server.request.duration')`
- **AND** the response is rendered into Prometheus matrix shape

#### Scenario: sum-by delegates to aggregation
- **WHEN** `GET /compat/prom/api/v1/query_range?query=sum by (service) ({metricName="http.server.request.duration"})&start=...&end=...&step=60`
- **THEN** the underlying scan invokes the aggregation primitives with `function=sum`, `column=value_double`, `groupBy=service`, `interval=1m`

#### Scenario: Raw selector returns matrix points
- **WHEN** `GET /compat/prom/api/v1/query_range?query={metricName="http.server.request.duration"}&start=...&end=...&step=60`
- **THEN** the underlying scan returns matching rows
- **AND** the response groups them per distinct label tuple into matrix entries

### Requirement: Prometheus shim non-preservations

The Prometheus shim SHALL NOT support:

- Any PromQL function outside `count_over_time` and `sum`. Specifically: `rate()`, `irate()`, `histogram_quantile()`, `topk`, `bottomk`, `avg`, `stddev`, `predict_linear`, `delta`, `idelta`, etc. Requests carrying these SHALL return HTTP 400 naming the unsupported function and pointing at `/v1/metrics/aggregate`.
- Recording rules and alerting rules.
- The `/api/v1/series` endpoint (out of v1 scope).
- Negative time-window queries or queries crossing `now()`.
- Operators `>`, `<`, `==`, `!=`, `and`, `or`, `unless`. Requests carrying these SHALL return HTTP 400.

#### Scenario: Unsupported PromQL function rejected
- **WHEN** `GET /compat/prom/api/v1/query_range?query=histogram_quantile(0.99, ...)&...`
- **THEN** the response status is 400 with a message naming `histogram_quantile` as unsupported and listing the supported `count_over_time` and `sum by` forms

#### Scenario: Unsupported PromQL operator rejected
- **WHEN** the query contains a comparison operator (`> 100`)
- **THEN** the response status is 400 with a message that PromQL operators are unsupported in this shim
