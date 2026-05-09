## ADDED Requirements

### Requirement: GET /v1/traces search endpoint

The system SHALL expose `GET /v1/traces` returning a compact JSON envelope of `traces/v1` rows matching the supplied criteria. Each row SHALL contain the camelCase form of the on-disk columns (`traceIdHex`, `spanIdHex`, `parentSpanIdHex`, `name`, `kind`, `kindText`, `startTimeUnixNano`, `endTimeUnixNano`, `durationNano`, `statusCode`, `statusText`, `statusMessage`, `resourceServiceName`, `scopeName`, etc.). The `schemaId` SHALL be `traces/v1`.

#### Scenario: Search returns matching span rows
- **WHEN** `GET /v1/traces?service=checkout&kind=SERVER&since=1h&limit=10` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** `schemaId` is `traces/v1`
- **AND** every returned row's `resourceServiceName` is `checkout` and `kindText` is `SERVER`

### Requirement: Traces-specific criteria

`GET /v1/traces` SHALL accept the following criteria in addition to the common `read-api` parameters:

- `name` — operation name match. Exact match by default; supports a single leading `*` or trailing `*` for prefix/suffix wildcards (no full glob, no regex)
- `kind` — exact match on `kind_text` (`UNSPECIFIED` | `INTERNAL` | `SERVER` | `CLIENT` | `PRODUCER` | `CONSUMER`)
- `statusCode` — exact match on `status_text` (`UNSET` | `OK` | `ERROR`)
- `httpStatusCodeMin` — inclusive lower bound on the `http_response_status_code` promoted column
- `traceId` — equality on `trace_id_hex` (32 lowercase hex chars; alias for the `/v1/traces/{traceId}` by-ID endpoint)
- `parentSpanId` — equality on `parent_span_id_hex` (16 lowercase hex chars; finds child spans of a given parent)
- `attribute.<key>` — equality on a single attribute key inside `attributes_json` (one such pair per request in v1)

#### Scenario: name with trailing wildcard
- **WHEN** `GET /v1/traces?name=GET+/orders/*&since=1h`
- **THEN** every returned row's `name` starts with `GET /orders/`

#### Scenario: kind enum mismatch rejected
- **WHEN** `GET /v1/traces?kind=BANANA&since=1h`
- **THEN** the response status is 400 with a message listing the supported `kind` values

#### Scenario: httpStatusCodeMin filters by HTTP status
- **WHEN** `GET /v1/traces?httpStatusCodeMin=500&since=1h`
- **THEN** every returned row has `httpResponseStatusCode >= 500`

#### Scenario: parentSpanId finds child spans
- **WHEN** `GET /v1/traces?parentSpanId=051581bf3cb55c13&since=1h`
- **THEN** every returned row has `parentSpanIdHex == "051581bf3cb55c13"`

### Requirement: GET /v1/traces/{traceId} returns the full span tree

The system SHALL expose `GET /v1/traces/{traceId}` returning every span in that trace within the configured search window (default last 24 hours; overridable via `since`/`until`). The response SHALL be an OTLP `ResourceSpans`-shaped JSON envelope under `_links` — i.e., the JSON the OTLP write-side accepts on POST, plus a top-level `_links` block. Spans SHALL be grouped under their originating `ResourceSpans` and `ScopeSpans` exactly as on the write side.

#### Scenario: Trace-by-ID returns OTLP-shaped tree
- **WHEN** `GET /v1/traces/5b8aa5a2d2c872e8321cf37308d69df2` arrives with a valid bearer
- **AND** that trace exists within the search window
- **THEN** the response status is 200
- **AND** the response body has top-level `resourceSpans` and `_links`
- **AND** every span's `traceId` equals `5b8aa5a2d2c872e8321cf37308d69df2`

#### Scenario: Trace tree carries cross-signal links
- **WHEN** a trace-by-ID response is returned
- **THEN** the top-level `_links.logs` is `/v1/logs?traceId=<id>&since=<trace.start>&until=<trace.end>`
- **AND** the top-level `_links.metricsWithExemplars` is `/v1/metrics?exemplarTraceId=<id>&since=<trace.start>&until=<trace.end>`

#### Scenario: Unknown trace ID returns 404
- **WHEN** `GET /v1/traces/<hex-not-in-window>` arrives with a valid bearer
- **THEN** the response status is 404 with a message naming the missing ID and the searched window

#### Scenario: Malformed trace ID returns 400
- **WHEN** `GET /v1/traces/zzzz`
- **THEN** the response status is 400 with a message saying the path segment must be 32 lowercase hex characters

#### Scenario: Explicit since/until widens or narrows the window
- **WHEN** `GET /v1/traces/<id>?since=2026-04-01T00:00:00Z&until=2026-05-01T00:00:00Z`
- **THEN** the executor scans partition directories under that bounded window
- **AND** if the trace is found there, returns 200; otherwise 404

### Requirement: GET /v1/spans/{spanId} returns a single span

The system SHALL expose `GET /v1/spans/{spanId}` returning the single span identified by its 16-byte ID rendered as 16 lowercase hex characters, within the configured search window (default last 24 hours). The response SHALL be a JSON envelope with a single OTLP `Span` (under `span` key) and a top-level `_links` block.

#### Scenario: Span-by-ID returns one span
- **WHEN** `GET /v1/spans/051581bf3cb55c13` arrives with a valid bearer
- **AND** that span exists within the search window
- **THEN** the response status is 200
- **AND** the response body has top-level `span` and `_links`
- **AND** the `span.spanId` (in OTLP-faithful base64 OR hex per the OTLP/HTTP-JSON spec for byte fields) round-trips to the path's `051581bf3cb55c13`

#### Scenario: Span response carries trace and logs links
- **WHEN** a span-by-ID response is returned
- **THEN** the top-level `_links.trace` is `/v1/traces/<traceIdOfThatSpan>`
- **AND** the top-level `_links.logs` is `/v1/logs?traceId=<traceId>&spanId=<spanId>&since=<span.start>&until=<span.end>`

#### Scenario: Unknown span ID returns 404
- **WHEN** the requested span is not found within the search window
- **THEN** the response status is 404

### Requirement: Per-row trace links on search responses

When `GET /v1/traces` returns rows from a search, each row SHALL carry `_links.trace = /v1/traces/<traceIdHex>` so a client can drill into the full tree. Search rows SHALL NOT individually carry `_links.span` (every row already represents a span, and `self` plus the trace tree cover the navigation needs).

#### Scenario: Search row links to its trace
- **WHEN** `GET /v1/traces?service=checkout&since=1h` returns rows
- **THEN** every row carries `_links.trace = "/v1/traces/<that-row's-traceIdHex>"`
