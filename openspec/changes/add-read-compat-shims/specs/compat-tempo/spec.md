## ADDED Requirements

### Requirement: Tempo shim — pinned to Tempo 2.x

The system SHALL expose a Tempo-compatible shim under `/compat/tempo/api/` when `crashler.compat.tempo.enabled` is `true`. The shim SHALL pin to the Tempo 2.x HTTP API. Endpoints in v1:

- `GET /compat/tempo/api/echo`
- `GET /compat/tempo/api/search`
- `GET /compat/tempo/api/traces/{traceId}`

All endpoints SHALL return `application/json` and SHALL share `read-api`'s Bearer auth, tenant scoping, and execution timeout. Responses SHALL match the shape Grafana's Tempo data source expects from a Tempo 2.x server enough to satisfy "Test connection", "Search", and "View trace" workflows.

#### Scenario: Echo returns 200 with Tempo's expected body shape
- **WHEN** `GET /compat/tempo/api/echo` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** the body is the literal string `echo` (Tempo's connection-test convention)

### Requirement: Tempo search

`GET /compat/tempo/api/search` SHALL accept the following query parameters and translate them into the same predicate compiler used by `GET /v1/traces`:

- `tags=<key>=<value>[ <key>=<value>...]` — space-separated equality pairs; each compiles to either a typed-column equality (when the key matches a known top-level column) or a `JsonAttributeEquals('attributes_json', key, value)` predicate
- `minDuration` / `maxDuration` — durations on `duration_unix_nano` (e.g. `100ms`, `1s`)
- `start`, `end` — Tempo's window in unix seconds; mapped to `since`/`until` unix-nano
- `limit` — page size, capped at `crashler.read.max_page_size`
- `service.name` — a Tempo-specific shorthand for the `service` filter

The response SHALL be `{traces: [{traceID, rootServiceName, rootTraceName, startTimeUnixNano, durationMs, ...}]}`. Each trace SHALL be the root span of a distinct trace ID matching the criteria, surfaced via existing scanner logic.

#### Scenario: Search returns Tempo-shaped traces
- **WHEN** `GET /compat/tempo/api/search?service.name=checkout&start=1714824000&end=1714827600&limit=20` arrives with a valid bearer
- **THEN** the response status is 200
- **AND** the body has top-level `traces` array whose elements carry `traceID`, `rootServiceName`, `rootTraceName`, `startTimeUnixNano` (string), and `durationMs` (number)

#### Scenario: Search with tags compiles to predicates
- **WHEN** `GET /compat/tempo/api/search?tags=http.method=POST http.route=/checkout&start=...&end=...`
- **THEN** the underlying scan applies `JsonAttributeEquals('attributes_json', 'http.method', 'POST')` AND `JsonAttributeEquals('attributes_json', 'http.route', '/checkout')` (subject to the multi-attribute cap)

#### Scenario: Search rejects unsupported tag count
- **WHEN** the `tags` parameter exceeds `crashler.read.max_attribute_filters`
- **THEN** the response status is 400 with a message naming the cap

### Requirement: Tempo trace-by-ID delegates to /v1/traces/{traceId}

`GET /compat/tempo/api/traces/{traceId}` SHALL invoke the existing `App\Read\Controller\ReadTraceController` with `Accept: application/otlp+json` and return the OTLP `ResourceSpans`-shaped JSON. The response body SHALL be byte-identical to a `GET /v1/traces/{traceId}` request with the same `Accept` header. Cross-signal `_links` SHALL be present in the body.

#### Scenario: Trace-by-ID returns OTLP-shape JSON
- **WHEN** `GET /compat/tempo/api/traces/5b8aa5a2d2c872e8321cf37308d69df2` arrives with a valid bearer
- **THEN** the response is byte-identical to `GET /v1/traces/5b8aa5a2d2c872e8321cf37308d69df2` with `Accept: application/otlp+json`

#### Scenario: Unknown trace ID returns 404
- **WHEN** the requested trace ID is not in the configured search window
- **THEN** the response status is 404 with a Tempo-shaped error envelope `{status: "error", error: "..."}`

### Requirement: Tempo shim non-preservations

The Tempo shim SHALL NOT support:

- TraceQL queries (`q=` parameter). Requests carrying `q=` SHALL return HTTP 400 naming the unsupported feature and listing the supported `tags` shape.
- Streaming responses (`streamingFlags`). The shim returns complete responses only.
- `X-Scope-OrgID` header — tenancy is bound to the Bearer token.
- The `metrics_summary` endpoint and other Tempo telemetry-specific endpoints.

#### Scenario: TraceQL request rejected
- **WHEN** `GET /compat/tempo/api/search?q=...` arrives
- **THEN** the response status is 400 with a message naming TraceQL as unsupported and pointing at the supported `tags` parameter
