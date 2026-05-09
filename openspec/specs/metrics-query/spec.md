## Purpose

Defines the `Metric` API Platform resource that powers `GET /v1/metrics`. Exposes a search-by-criteria collection endpoint over the on-disk `metrics/v1` Parquet files, with typed filters for service / environment / metric name / metric type / aggregation temporality / exemplar trace ID / attribute equality, plus a per-row hypermedia affordance pointing into the trace referenced by a metric's first exemplar. The `exemplarTraceId` filter is also the canonical shorthand used by Trace.Get responses to surface "metrics referencing this trace".

## Requirements

### Requirement: Metric ApiResource with GetCollection operation

The system SHALL declare an `App\Read\Resource\Metric` PHP class as an `#[ApiResource]` with a single `GetCollection` operation at `/v1/metrics`. Provider: `App\Read\State\MetricsStateProvider`. The Resource SHALL list properties matching the camelCase form of the on-disk columns: `metricName`, `metricType`, `metricTypeCode`, `timeUnixNano`, `startTimeUnixNano`, `metricUnit`, `aggregationTemporalityText`, `valueDouble`, `valueInt`, `count`, `sum`, `min`, `max`, `bucketsJson`, `exponentialHistogramJson`, `quantilesJson`, `exemplarsJson`, `attributesJson`, `metricAttributesJson`, `resourceServiceName`, etc. The `schemaId` SHALL be `metrics/v1`.

#### Scenario: Search returns matching metric data-point rows
- **WHEN** `GET /v1/metrics?service=checkout&metricType=HISTOGRAM&since=1h&limit=10` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** the response in the requested format carries `schemaId = metrics/v1`
- **AND** every returned row has `metricType == "HISTOGRAM"` and `resourceServiceName == "checkout"`

### Requirement: Metrics-specific filters

The Metric Resource SHALL declare the following `#[ApiFilter]`s in addition to the common filters defined in `read-api`:

- `metricName` — exact match on `metric_name`. Wildcards are NOT supported in v1 (defer to a follow-up if requested)
- `metricType` — exact match on `metric_type` (`SUM` | `GAUGE` | `HISTOGRAM` | `EXPONENTIAL_HISTOGRAM` | `SUMMARY`)
- `aggregationTemporality` — exact match on `aggregation_temporality_text` (`UNSPECIFIED` | `DELTA` | `CUMULATIVE`); only meaningful for SUM / HISTOGRAM / EXPONENTIAL_HISTOGRAM rows
- `exemplarTraceId` — find rows whose `exemplars_json` carries an exemplar referring to that trace ID. Compiles to `JsonAttributeEquals('exemplars_json', 'traceId', v)` which decodes the JSON and walks the exemplar list checking the `traceId` field (NOT a substring match — defends against false positives where a 32-char hex appears in another field). Used by Trace.Get responses to populate the `metricsWithExemplars` affordance
- `attribute.<key>` — equality via `JsonAttributeEquals('attributes_json', key, v)` (decoded JSON walk; one such filter per request in v1)

#### Scenario: metricType enum mismatch rejected
- **WHEN** `GET /v1/metrics?metricType=BANANA&since=1h`
- **THEN** the response status is 400 with a message listing the supported metric types

#### Scenario: exemplarTraceId filters via decoded walk, not substring
- **WHEN** `GET /v1/metrics?exemplarTraceId=5b8aa5a2d2c872e8321cf37308d69df2&since=1h`
- **THEN** every returned row's `exemplarsJson`, when JSON-decoded, contains an entry whose `traceId` field equals `5b8aa5a2d2c872e8321cf37308d69df2`
- **AND** rows whose `exemplarsJson` merely contains the substring "5b8aa5a2d2c872e8321cf37308d69df2" elsewhere (e.g., as a value in another field) are NOT returned

#### Scenario: aggregationTemporality filter
- **WHEN** `GET /v1/metrics?metricType=SUM&aggregationTemporality=DELTA&since=1h`
- **THEN** every returned row has `metricType == "SUM"` and `aggregationTemporalityText == "DELTA"`

#### Scenario: Wildcard in metricName is rejected
- **WHEN** `GET /v1/metrics?metricName=http.*&since=1h`
- **THEN** the response status is 400 with a message that wildcards in `metricName` are not supported in v1

### Requirement: Per-row exemplar affordance

When a Metric row's `exemplarsJson`, after JSON-decoding, contains at least one exemplar with a `traceId`, the row's affordances SHALL include `exemplars = /v1/traces/<the-first-exemplar-traceId-hex>`. If a row has multiple exemplars referring to different traces, only the first one is linked from the `exemplars` affordance; clients that need the full set can extract them from `exemplarsJson` and follow the IDs themselves. Rows whose decoded `exemplarsJson` is empty SHALL NOT carry the `exemplars` affordance.

#### Scenario: Row with exemplar carries exemplars affordance
- **WHEN** a returned row's `exemplarsJson` decodes to `[{"traceId":"5b8a…","spanId":"0515…","timeUnixNano":"1714…","asDouble":1.5}]`
- **THEN** that row's affordances include `exemplars = /v1/traces/5b8a…`

#### Scenario: Row without exemplars has no exemplar affordance
- **WHEN** a returned row's `exemplarsJson` decodes to `[]`
- **THEN** that row's affordances do NOT contain an `exemplars` rel

#### Scenario: Multi-exemplar row links only the first
- **WHEN** a returned row's `exemplarsJson` decodes to two entries with different `traceId` values
- **THEN** that row's `exemplars` affordance resolves to the first entry's traceId
- **AND** the row still includes the full `exemplarsJson` so clients can iterate themselves

### Requirement: Metrics-from-trace shorthand via exemplarTraceId

`GET /v1/metrics?exemplarTraceId=<hex>&since=...&until=...` SHALL be the canonical link target rendered in a Trace.Get response's `metricsWithExemplars` affordance. The endpoint behaves identically to a regular search whose `exemplarTraceId` filter happens to be set.

#### Scenario: Following the metricsWithExemplars affordance from a trace
- **WHEN** `GET /v1/traces/<hex>` returns a response whose `metricsWithExemplars` affordance is `/v1/metrics?exemplarTraceId=<hex>&since=A&until=B`
- **AND** a follow-up `GET` against that exact URL is made
- **THEN** the response status is 200 and every returned metric row's decoded `exemplarsJson` contains an entry whose `traceId` is `<hex>`
