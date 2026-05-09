## ADDED Requirements

### Requirement: GET /v1/logs search endpoint

The system SHALL expose `GET /v1/logs` returning a compact JSON envelope of `logs/v1` rows matching the supplied criteria. Each row SHALL contain the camelCase form of the on-disk columns (`timeUnixNano`, `severityNumber`, `severityText`, `bodyJson`, `attributesJson`, `resourceServiceName`, `traceIdHex`, `spanIdHex`, etc.). The `schemaId` SHALL be `logs/v1`.

#### Scenario: Search returns matching log rows
- **WHEN** `GET /v1/logs?service=checkout&since=1h&limit=10` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** `schemaId` is `logs/v1`
- **AND** `rows` contains at most 10 entries, each carrying `timeUnixNano`, `severityNumber`, `bodyJson`, `attributesJson`, and any populated promoted columns
- **AND** every row's `resourceServiceName` is `checkout`

#### Scenario: Default time window
- **WHEN** `GET /v1/logs?service=checkout` arrives with no `since`/`until`
- **THEN** the response covers the last 1 hour relative to request arrival

### Requirement: Logs-specific criteria

`GET /v1/logs` SHALL accept the following criteria in addition to the common `read-api` parameters:

- `severityNumber` — exact-match integer (1–24 per OTLP)
- `severityNumberMin` — inclusive lower bound (e.g. `severityNumberMin=17` matches ERROR and above)
- `severityText` — exact match against `severity_text` column
- `traceId` — equality on `trace_id_hex` (32 lowercase hex chars)
- `spanId` — equality on `span_id_hex` (16 lowercase hex chars)
- `eventName` — equality on the `event_name` promoted column
- `bodyContains` — case-sensitive substring match against `body_json` (no Parquet pushdown — combine with a service or time filter)
- `attribute.<key>` — equality on a single attribute key inside `attributes_json` (one such pair per request in v1)

#### Scenario: severityNumberMin filters out lower severities
- **WHEN** `GET /v1/logs?severityNumberMin=17&since=1h`
- **THEN** every returned row has `severityNumber >= 17`

#### Scenario: traceId filter restricts to one trace
- **WHEN** `GET /v1/logs?traceId=5b8aa5a2d2c872e8321cf37308d69df2&since=1h`
- **THEN** every returned row's `traceIdHex` equals `5b8aa5a2d2c872e8321cf37308d69df2`

#### Scenario: traceId of wrong length rejected
- **WHEN** `GET /v1/logs?traceId=cafe&since=1h`
- **THEN** the response status is 400 with a message naming `traceId` and the expected 32-char length

#### Scenario: attribute filter matches log records carrying that key+value
- **WHEN** `GET /v1/logs?attribute.exception.type=RuntimeException&since=1h`
- **THEN** every returned row has `RuntimeException` somewhere inside its `attributesJson`
- **AND** the row's `attributesJson` contains the literal key `exception.type`

#### Scenario: Two attribute filters in one request rejected in v1
- **WHEN** `GET /v1/logs?attribute.exception.type=X&attribute.foo=Y&since=1h`
- **THEN** the response status is 400 with a message that v1 supports at most one `attribute.<key>` filter per request

### Requirement: Per-row trace and span links

When a logs row carries a non-null `traceIdHex` (hex-decoded from the original trace_id bytes), the row's `_links.trace` SHALL be `/v1/traces/<traceIdHex>`. When the row carries a non-null `spanIdHex`, the row's `_links.span` SHALL be `/v1/spans/<spanIdHex>`. Rows without these IDs SHALL NOT carry the corresponding link relations.

#### Scenario: Row with trace + span carries both links
- **WHEN** a returned row has both `traceIdHex` and `spanIdHex` populated
- **THEN** that row's `_links.trace` and `_links.span` resolve to the corresponding `/v1/traces/...` and `/v1/spans/...` URLs

#### Scenario: Row without trace context omits trace link
- **WHEN** a returned row has `traceIdHex == null`
- **THEN** that row's `_links` does NOT contain a `trace` rel
- **AND** that row's `_links` does NOT contain a `span` rel even if `spanIdHex` somehow exists (defensive: spanIdHex without traceIdHex is invalid OTLP)

### Requirement: Logs-from-trace shorthand via traceId

`GET /v1/logs?traceId=<hex>&since=...&until=...` SHALL be the canonical link target from a trace-by-ID response's `_links.logs`. The endpoint behaves identically to a regular search whose `traceId` filter happens to be set.

#### Scenario: Following _links.logs from a trace returns the logs from that trace
- **WHEN** `GET /v1/traces/<hex>` returns a response with `_links.logs = "/v1/logs?traceId=<hex>&since=A&until=B"`
- **AND** a follow-up `GET` against that exact URL is made
- **THEN** the response status is 200 and every returned log row has `traceIdHex == <hex>`
