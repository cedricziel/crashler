## MODIFIED Requirements

### Requirement: Metrics-specific filters

The Metric Resource SHALL declare the following `#[ApiFilter]`s in addition to the common filters defined in `read-api`:

- `metricName` — exact match on `metric_name`. Wildcards are NOT supported in v1 (defer to a follow-up if requested)
- `metricType` — exact match on `metric_type` (`SUM` | `GAUGE` | `HISTOGRAM` | `EXPONENTIAL_HISTOGRAM` | `SUMMARY`)
- `aggregationTemporality` — exact match on `aggregation_temporality_text` (`UNSPECIFIED` | `DELTA` | `CUMULATIVE`); only meaningful for SUM / HISTOGRAM / EXPONENTIAL_HISTOGRAM rows
- `exemplarTraceId` — find rows whose `exemplars_json` carries an exemplar referring to that trace ID. Compiles to `JsonAttributeEquals('exemplars_json', 'traceId', v)` which decodes the JSON and walks the exemplar list checking the `traceId` field (NOT a substring match — defends against false positives where a 32-char hex appears in another field). Used by Trace.Get responses to populate the `metricsWithExemplars` affordance
- `attribute.<key>` — equality via `JsonAttributeEquals('attributes_json', key, v)` (decoded JSON walk). Multiple distinct `attribute.<key>` filters compose with logical AND in a single request, up to the per-request cap defined by `read-api` (`crashler.read.max_attribute_filters`, default 5).

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

#### Scenario: Multiple attribute filters compose with AND
- **WHEN** `GET /v1/metrics?attribute.k8s.cluster=prod&attribute.region=eu-west-1&since=1h`
- **THEN** the request is accepted
- **AND** every returned row's decoded `attributesJson` carries entries for both `k8s.cluster=prod` and `region=eu-west-1`

#### Scenario: Six attribute filters in one request rejected
- **WHEN** the request carries six distinct `attribute.<key>` parameters (and the configured cap is 5)
- **THEN** the response status is 400 with a message naming the cap (5)
