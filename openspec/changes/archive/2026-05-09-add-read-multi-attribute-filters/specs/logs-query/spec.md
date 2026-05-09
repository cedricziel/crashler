## MODIFIED Requirements

### Requirement: Logs-specific filters

The Log Resource SHALL declare the following `#[ApiFilter]`s in addition to the common filters defined in `read-api`:

- `severityNumber` — exact-match integer (1–24 per OTLP); compiles to `ColumnEquals('severity_number', v)`
- `severityNumberMin` — inclusive lower bound; compiles to `ColumnGreaterEqual('severity_number', v)` and is row-group-stat push-down eligible
- `severityText` — exact match; compiles to `ColumnEquals('severity_text', v)`
- `traceId` — equality on `trace_id_hex` (exactly 32 lowercase hex chars)
- `spanId` — equality on `span_id_hex` (exactly 16 lowercase hex chars)
- `eventName` — equality on the `event_name` promoted column
- `bodyContains` — case-sensitive substring match against `body_json` (no row-group push-down — combine with a service or time filter); compiles to `JsonStringContains('body_json', v)`
- `attribute.<key>` — equality on a single attribute key inside `attributes_json`; compiles to `JsonAttributeEquals('attributes_json', key, v)` which decodes the JSON and walks the attribute array (NOT a substring match — defends against false positives where a value contains the key spelling). Multiple distinct `attribute.<key>` filters compose with logical AND in a single request, up to the per-request cap defined by `read-api` (`crashler.read.max_attribute_filters`, default 5).

#### Scenario: severityNumberMin filters out lower severities
- **WHEN** `GET /v1/logs?severityNumberMin=17&since=1h`
- **THEN** every returned row has `severityNumber >= 17`

#### Scenario: traceId filter restricts to one trace
- **WHEN** `GET /v1/logs?traceId=5b8aa5a2d2c872e8321cf37308d69df2&since=1h`
- **THEN** every returned row's `traceIdHex` equals `5b8aa5a2d2c872e8321cf37308d69df2`

#### Scenario: traceId of wrong length rejected
- **WHEN** `GET /v1/logs?traceId=cafe&since=1h`
- **THEN** the response status is 400 with a message naming `traceId` and the expected 32-char length

#### Scenario: attribute filter matches by decoded JSON walk, not substring
- **WHEN** `GET /v1/logs?attribute.exception.type=RuntimeException&since=1h`
- **THEN** every returned row's `attributesJson` contains a JSON entry of shape `{"key":"exception.type","value":{"stringValue":"RuntimeException"}}` after decoding
- **AND** rows whose `attributesJson` merely contains the substring "exception.type" or "RuntimeException" elsewhere (e.g., inside another attribute's value) are NOT returned

#### Scenario: Multiple attribute filters compose with AND
- **WHEN** `GET /v1/logs?attribute.exception.type=RuntimeException&attribute.http.method=POST&since=1h`
- **THEN** the request is accepted
- **AND** every returned row's decoded `attributesJson` carries an entry of shape `{"key":"exception.type","value":{"stringValue":"RuntimeException"}}`
- **AND** every returned row's decoded `attributesJson` ALSO carries an entry of shape `{"key":"http.method","value":{"stringValue":"POST"}}`
- **AND** rows that match only one of the two attribute filters are NOT returned

#### Scenario: Six attribute filters in one request rejected
- **WHEN** the request carries six distinct `attribute.<key>` parameters (and the configured cap is 5)
- **THEN** the response status is 400 with a message naming the cap (5) and asking the client to narrow the filters
