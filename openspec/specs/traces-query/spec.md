## Purpose

Defines the `Trace` and `Span` API Platform resources that power `GET /v1/traces`, `GET /v1/traces/{traceId}`, and `GET /v1/spans/{spanId}`. Exposes search-by-criteria over the on-disk `traces/v1` Parquet files plus full-trace-tree retrieval by ID (with OTLP `ResourceSpans`-shaped JSON when negotiated as `application/otlp+json`) and single-span retrieval by ID. Per-row affordances let clients drill from a span search row into the full trace tree and from there into related logs and metrics.

## Requirements

### Requirement: Trace ApiResource with GetCollection + Get operations

The system SHALL declare an `App\Read\Resource\Trace` PHP class as an `#[ApiResource]` with two operations:

- `GetCollection` at `/v1/traces` for search by criteria. Provider: `App\Read\State\TracesStateProvider`. Returns one row per matching span.
- `Get` at `/v1/traces/{traceId}` for retrieving a full span tree by ID. Provider: `App\Read\State\TraceStateProvider`. Returns OTLP `ResourceSpans`-shaped JSON when negotiated as `application/otlp+json`; returns the AP-default Hydra normalization for other formats.

The Resource SHALL list properties matching the camelCase form of the on-disk columns: `traceIdHex`, `spanIdHex`, `parentSpanIdHex`, `name`, `kind`, `kindText`, `startTimeUnixNano`, `endTimeUnixNano`, `durationNano`, `statusCode`, `statusText`, `statusMessage`, `resourceServiceName`, `scopeName`, etc. The `schemaId` SHALL be `traces/v1` for collection responses.

#### Scenario: Search returns matching span rows
- **WHEN** `GET /v1/traces?service=checkout&kind=SERVER&since=1h&limit=10` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** the response in the requested format carries `schemaId = traces/v1`
- **AND** every returned row's `resourceServiceName` is `checkout` and `kindText` is `SERVER`

### Requirement: Trace-specific filters

The Trace Resource SHALL declare the following `#[ApiFilter]`s in addition to the common filters defined in `read-api`:

- `name` — operation name match. Exact match by default; supports a single leading `*` or trailing `*` for prefix/suffix wildcards (no full glob, no regex). Compiles to `ColumnEquals('name', v)` / `ColumnLikePrefix('name', prefix)` / `ColumnLikeSuffix('name', suffix)`
- `kind` — exact match on `kind_text` (`UNSPECIFIED` | `INTERNAL` | `SERVER` | `CLIENT` | `PRODUCER` | `CONSUMER`)
- `statusCode` — exact match on `status_text` (`UNSET` | `OK` | `ERROR`)
- `httpStatusCodeMin` — inclusive lower bound on `http_response_status_code`; row-group push-down eligible
- `traceId` — equality on `trace_id_hex` (32 lowercase hex chars; alias for the `/v1/traces/{traceId}` Item operation)
- `parentSpanId` — equality on `parent_span_id_hex` (16 lowercase hex chars; finds child spans of a given parent)
- `attribute.<key>` — equality via `JsonAttributeEquals('attributes_json', key, v)` (decoded JSON walk, not substring; one such filter per request in v1)

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

### Requirement: GET /v1/traces/{traceId} returns the full span tree (OTLP-shaped on negotiation)

The Trace.Get operation at `GET /v1/traces/{traceId}` SHALL retrieve every span in that trace within the configured search window (default `crashler.read.span_lookup_window_hours` = 24; overridable via `since`/`until`). When the request negotiates `Accept: application/otlp+json`, the response SHALL be the OTLP `ResourceSpans`-shaped JSON wrapped in a `_links` block — i.e., the JSON the OTLP write side accepts on POST, plus a top-level `_links` block with cross-signal navigation. Spans SHALL be grouped under their originating `ResourceSpans` and `ScopeSpans` exactly as on the write side. Other Accept values SHALL return the AP-default normalized Trace resource.

The OTLP-shape `traceId` and `spanId` fields SHALL be emitted as **lowercase hex** per the OTLP/HTTP-JSON spec's special case for those byte fields (other byte fields use base64).

#### Scenario: Trace-by-ID OTLP-shaped response
- **WHEN** `GET /v1/traces/5b8aa5a2d2c872e8321cf37308d69df2` arrives with `Accept: application/otlp+json` and a valid bearer
- **AND** that trace exists within the search window
- **THEN** the response status is 200
- **AND** the Content-Type is `application/otlp+json`
- **AND** the response body has top-level `resourceSpans` and `_links`
- **AND** every span's `traceId` (lowercase hex) equals `5b8aa5a2d2c872e8321cf37308d69df2`

#### Scenario: Trace tree carries cross-signal affordances
- **WHEN** a Trace.Get response is returned in any format
- **THEN** the top-level affordances include `logs = /v1/logs?traceId=<id>&since=<trace.start>&until=<trace.end>`
- **AND** the top-level affordances include `metricsWithExemplars = /v1/metrics?exemplarTraceId=<id>&since=<trace.start>&until=<trace.end>`

#### Scenario: Unknown trace ID returns 404
- **WHEN** `GET /v1/traces/<hex-not-in-window>` arrives with a valid bearer
- **THEN** the response status is 404 with a message naming the missing ID and the searched window

#### Scenario: Malformed trace ID returns 400
- **WHEN** `GET /v1/traces/zzzz`
- **THEN** the response status is 400 with a message saying the path segment must be 32 lowercase hex characters

#### Scenario: Explicit since/until widens or narrows the window
- **WHEN** `GET /v1/traces/<id>?since=2026-04-01T00:00:00Z&until=2026-05-01T00:00:00Z`
- **THEN** the scanner reads partition directories under that bounded window
- **AND** if the trace is found there, returns 200; otherwise 404

### Requirement: Span ApiResource with Get operation

The system SHALL declare an `App\Read\Resource\Span` PHP class as an `#[ApiResource]` with a single `Get` operation at `/v1/spans/{spanId}`. Provider: `App\Read\State\SpanStateProvider`. The response SHALL carry the OTLP `Span` shape under a top-level `span` key plus a `_links` block:

```json
{
  "span": { /* OTLP Span shape */ },
  "_links": {
    "self": "/v1/spans/<spanId>",
    "trace": "/v1/traces/<traceIdOfThatSpan>",
    "logs": "/v1/logs?traceId=<traceId>&spanId=<spanId>&since=...&until=..."
  }
}
```

The default lookup window matches the Trace.Get operation (`crashler.read.span_lookup_window_hours` = 24).

#### Scenario: Span-by-ID returns one span
- **WHEN** `GET /v1/spans/051581bf3cb55c13` arrives with a valid bearer
- **AND** that span exists within the search window
- **THEN** the response status is 200
- **AND** the response body has top-level `span` and `_links`
- **AND** the `span.spanId` (lowercase hex) equals `051581bf3cb55c13`

#### Scenario: Span response carries trace and logs affordances
- **WHEN** a Span.Get response is returned
- **THEN** the affordances include `trace = /v1/traces/<traceIdOfThatSpan>`
- **AND** the affordances include `logs = /v1/logs?traceId=<traceId>&spanId=<spanId>&since=<span.start>&until=<span.end>`

#### Scenario: Unknown span ID returns 404
- **WHEN** the requested span is not found within the search window
- **THEN** the response status is 404

### Requirement: Per-row trace affordances on collection responses

When `GET /v1/traces` returns rows from a search, each row SHALL carry an affordance `trace = /v1/traces/<traceIdHex>` so a client can drill into the full tree. Search rows SHALL NOT individually carry a `span` affordance (every row already represents a span; `self` plus the trace tree cover the navigation needs).

#### Scenario: Search row links to its trace
- **WHEN** `GET /v1/traces?service=checkout&since=1h` returns rows
- **THEN** every row carries an affordance `trace = /v1/traces/<that-row's-traceIdHex>`
