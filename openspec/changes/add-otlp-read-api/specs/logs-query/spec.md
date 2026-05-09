## ADDED Requirements

### Requirement: Log ApiResource with GetCollection operation

The system SHALL declare an `App\Read\Resource\Log` PHP class as an `#[ApiResource]` with a single `GetCollection` operation at `/v1/logs` (under the AP `routePrefix: /v1`). The Resource SHALL list properties matching the camelCase form of the on-disk columns: `timeUnixNano`, `severityNumber`, `severityText`, `bodyJson`, `attributesJson`, `resourceServiceName`, `resourceDeploymentEnvironment`, `resourceHostName`, `scopeName`, `scopeVersion`, `scopeSchemaUrl`, `traceIdHex`, `spanIdHex`, plus the documented promoted columns. The collection's State Provider SHALL be `App\Read\State\LogsStateProvider`. The `schemaId` SHALL be `logs/v1` (rendered into the response per the requested format).

#### Scenario: Search returns matching log rows
- **WHEN** `GET /v1/logs?service=checkout&since=1h&limit=10` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** the response in the requested format carries `schemaId = logs/v1`
- **AND** the rows collection contains at most 10 entries, each carrying `timeUnixNano`, `severityNumber`, `bodyJson`, `attributesJson`, and any populated promoted columns
- **AND** every row's `resourceServiceName` is `checkout`

#### Scenario: Default time window
- **WHEN** `GET /v1/logs?service=checkout` arrives with no `since`/`until`
- **THEN** the response covers the last 1 hour relative to request arrival

### Requirement: Logs-specific filters

The Log Resource SHALL declare the following `#[ApiFilter]`s in addition to the common filters defined in `read-api`:

- `severityNumber` — exact-match integer (1–24 per OTLP); compiles to `ColumnEquals('severity_number', v)`
- `severityNumberMin` — inclusive lower bound; compiles to `ColumnGreaterEqual('severity_number', v)` and is row-group-stat push-down eligible
- `severityText` — exact match; compiles to `ColumnEquals('severity_text', v)`
- `traceId` — equality on `trace_id_hex` (exactly 32 lowercase hex chars)
- `spanId` — equality on `span_id_hex` (exactly 16 lowercase hex chars)
- `eventName` — equality on the `event_name` promoted column
- `bodyContains` — case-sensitive substring match against `body_json` (no row-group push-down — combine with a service or time filter); compiles to `JsonStringContains('body_json', v)`
- `attribute.<key>` — equality on a single attribute key inside `attributes_json`; compiles to `JsonAttributeEquals('attributes_json', key, v)` which decodes the JSON and walks the attribute array (NOT a substring match — defends against false positives where a value contains the key spelling). Limited to one such filter per request in v1

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

#### Scenario: Two attribute filters in one request rejected in v1
- **WHEN** `GET /v1/logs?attribute.exception.type=X&attribute.foo=Y&since=1h`
- **THEN** the response status is 400 with a message that v1 supports at most one `attribute.<key>` filter per request

### Requirement: Per-row trace and span affordances

When a Log row carries a non-null `traceIdHex`, the row's affordances SHALL include `trace = /v1/traces/<traceIdHex>`. When the row carries a non-null `spanIdHex`, the row's affordances SHALL include `span = /v1/spans/<spanIdHex>`. Rows without these IDs SHALL NOT carry the corresponding affordances. The affordances are rendered into the requested format (Hydra `hydra:Operation`, HAL `_links.<rel>`, compact `_links.<rel>`, JSON:API `relationships.<rel>`).

#### Scenario: Row with trace + span carries both affordances
- **WHEN** a returned row has both `traceIdHex` and `spanIdHex` populated
- **THEN** that row's affordances resolve `trace` to `/v1/traces/<traceIdHex>` and `span` to `/v1/spans/<spanIdHex>`

#### Scenario: Row without trace context omits trace affordance
- **WHEN** a returned row has `traceIdHex == null`
- **THEN** that row's affordances do NOT contain a `trace` rel
- **AND** that row's affordances do NOT contain a `span` rel even if `spanIdHex` somehow exists (defensive: spanIdHex without traceIdHex is invalid OTLP)

### Requirement: Logs-from-trace shorthand via traceId

`GET /v1/logs?traceId=<hex>&since=...&until=...` SHALL be the canonical link target rendered in a Trace.Get response's `logs` affordance. The endpoint behaves identically to a regular search whose `traceId` filter happens to be set.

#### Scenario: Following the logs affordance from a trace returns the logs from that trace
- **WHEN** `GET /v1/traces/<hex>` returns a response with a `logs` affordance equal to `/v1/logs?traceId=<hex>&since=A&until=B`
- **AND** a follow-up `GET` against that exact URL is made
- **THEN** the response status is 200 and every returned log row has `traceIdHex == <hex>`
