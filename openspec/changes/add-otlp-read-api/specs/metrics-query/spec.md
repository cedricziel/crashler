## ADDED Requirements

### Requirement: GET /v1/metrics search endpoint

The system SHALL expose `GET /v1/metrics` returning a compact JSON envelope of `metrics/v1` rows matching the supplied criteria. Each row SHALL contain the camelCase form of the on-disk columns (`metricName`, `metricType`, `metricTypeCode`, `timeUnixNano`, `startTimeUnixNano`, `metricUnit`, `aggregationTemporalityText`, `valueDouble`, `valueInt`, `count`, `sum`, `min`, `max`, `bucketsJson`, `exponentialHistogramJson`, `quantilesJson`, `exemplarsJson`, `attributesJson`, `metricAttributesJson`, `resourceServiceName`, etc.). The `schemaId` SHALL be `metrics/v1`.

#### Scenario: Search returns matching metric data-point rows
- **WHEN** `GET /v1/metrics?service=checkout&metricType=HISTOGRAM&since=1h&limit=10` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** `schemaId` is `metrics/v1`
- **AND** every returned row has `metricType == "HISTOGRAM"` and `resourceServiceName == "checkout"`

### Requirement: Metrics-specific criteria

`GET /v1/metrics` SHALL accept the following criteria in addition to the common `read-api` parameters:

- `metricName` — exact match on the `metric_name` promoted column. Wildcards are NOT supported in v1 (defer to a follow-up if requested)
- `metricType` — exact match on `metric_type` (`SUM` | `GAUGE` | `HISTOGRAM` | `EXPONENTIAL_HISTOGRAM` | `SUMMARY`)
- `aggregationTemporality` — exact match on `aggregation_temporality_text` (`UNSPECIFIED` | `DELTA` | `CUMULATIVE`); only meaningful for SUM / HISTOGRAM / EXPONENTIAL_HISTOGRAM rows
- `exemplarTraceId` — substring match looking for the given hex inside `exemplars_json`. Returns rows whose data-point carries an exemplar referring to that trace ID. Used by trace-by-ID responses to populate `_links.metricsWithExemplars`
- `attribute.<key>` — equality on a single data-point attribute key inside `attributes_json` (one such pair per request in v1)

#### Scenario: metricType enum mismatch rejected
- **WHEN** `GET /v1/metrics?metricType=BANANA&since=1h`
- **THEN** the response status is 400 with a message listing the supported metric types

#### Scenario: exemplarTraceId filters to metrics referencing a trace
- **WHEN** `GET /v1/metrics?exemplarTraceId=5b8aa5a2d2c872e8321cf37308d69df2&since=1h`
- **THEN** every returned row's `exemplarsJson` contains a JSON array entry whose `traceId` is `5b8aa5a2d2c872e8321cf37308d69df2`

#### Scenario: aggregationTemporality filter
- **WHEN** `GET /v1/metrics?metricType=SUM&aggregationTemporality=DELTA&since=1h`
- **THEN** every returned row has `metricType == "SUM"` and `aggregationTemporalityText == "DELTA"`

#### Scenario: Wildcard in metricName is rejected
- **WHEN** `GET /v1/metrics?metricName=http.*&since=1h`
- **THEN** the response status is 400 with a message that wildcards in `metricName` are not supported in v1

### Requirement: Per-row exemplar link

When a metrics row's `exemplarsJson` contains at least one exemplar with a `traceId`, the row's `_links.exemplars` SHALL be `/v1/traces/<the-first-exemplar-traceId-hex>`. If a row has multiple exemplars referring to different traces, only the first one is linked from `_links.exemplars`; clients that need the full set can extract them from `exemplarsJson` and follow the IDs themselves. Rows whose `exemplarsJson` is `[]` SHALL NOT carry `_links.exemplars`.

#### Scenario: Row with exemplar carries exemplars link
- **WHEN** a returned row's `exemplarsJson` is `[{"traceId":"5b8a…","spanId":"0515…","timeUnixNano":"1714…","asDouble":1.5}]`
- **THEN** that row's `_links.exemplars` is `/v1/traces/5b8a…`

#### Scenario: Row without exemplars has no exemplar link
- **WHEN** a returned row's `exemplarsJson` is `[]`
- **THEN** that row's `_links` does NOT contain an `exemplars` rel

#### Scenario: Multi-exemplar row links only the first
- **WHEN** a returned row's `exemplarsJson` carries two entries with different `traceId` values
- **THEN** that row's `_links.exemplars` resolves to the first entry's traceId
- **AND** the row still includes the full `exemplarsJson` so clients can iterate themselves

### Requirement: Metrics-from-trace shorthand via exemplarTraceId

`GET /v1/metrics?exemplarTraceId=<hex>&since=...&until=...` SHALL be the canonical link target from a trace-by-ID response's `_links.metricsWithExemplars`. The endpoint behaves identically to a regular search whose `exemplarTraceId` filter happens to be set.

#### Scenario: Following _links.metricsWithExemplars from a trace
- **WHEN** `GET /v1/traces/<hex>` returns a response with `_links.metricsWithExemplars = "/v1/metrics?exemplarTraceId=<hex>&since=A&until=B"`
- **AND** a follow-up `GET` against that exact URL is made
- **THEN** the response status is 200 and every returned metric row's `exemplarsJson` contains an entry whose `traceId` is `<hex>`
