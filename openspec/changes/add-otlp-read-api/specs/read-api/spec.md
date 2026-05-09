## ADDED Requirements

### Requirement: HTTP read endpoints under /v1/

The system SHALL expose read endpoints alongside the existing OTLP write endpoints, sharing the same Symfony firewall, the same Bearer-token auth, and the same tenant model. Read traffic SHALL use HTTP `GET`. Read endpoints SHALL be:

- `GET /v1/logs` — search logs by criteria
- `GET /v1/traces` — search traces by criteria
- `GET /v1/traces/{traceId}` — retrieve a single trace tree by ID
- `GET /v1/spans/{spanId}` — retrieve a single span by ID
- `GET /v1/metrics` — search metrics by criteria

#### Scenario: GET on a write-only path is rejected
- **WHEN** a request arrives at a path that has both a POST (write) and a GET (read) handler — `/v1/logs`, `/v1/traces`, `/v1/metrics` — and the verb is GET
- **THEN** the request is routed to the read handler, not the write handler
- **AND** a missing/invalid bearer SHALL still return 401 just as it does on POST

#### Scenario: GET requires no body
- **WHEN** a `GET /v1/logs?...` request arrives with `Content-Length: 0`
- **THEN** the system processes it normally without rejecting on missing Content-Type

### Requirement: Bearer-token authentication and tenant scoping

Every read endpoint SHALL require a valid Bearer token that resolves to a known tenant via the existing `tenants` capability. The tenant slug derived from the token SHALL bound the underlying file glob: a query for tenant `acme` SHALL ONLY read files under `<storage-root>/<signal>/acme/`. Tenant escape SHALL be impossible by construction (the slug enters as a path segment, not as a SQL or filter argument).

#### Scenario: Missing bearer token returns 401
- **WHEN** a `GET /v1/logs?...` arrives without an `Authorization` header
- **THEN** the system responds with HTTP 401
- **AND** the response body is an error envelope with a `message` field

#### Scenario: Invalid bearer token returns 401
- **WHEN** a `GET /v1/traces?...` arrives with `Authorization: Bearer <unknown-token>`
- **THEN** the system responds with HTTP 401
- **AND** no Parquet files are read

#### Scenario: Tenant scope is enforced at the file glob
- **WHEN** tenant `acme` calls `GET /v1/logs?...`
- **THEN** the executor's input glob resolves under `<storage-root>/logs/acme/` exclusively
- **AND** files in `<storage-root>/logs/<other-tenant>/` are not read

### Requirement: Mandatory bounded time window

Every search endpoint SHALL accept a time window via either:

- both `since` and `until` parameters (RFC3339 strings or unix-nano integer-as-string), or
- a `since=<duration>` shorthand (e.g. `1h`, `15m`, `7d`) implying `until=<now>`,
- or no parameters at all, in which case the implicit window is "the last 1 hour".

The resolved `[since, until]` window SHALL be ≤ `crashler.read.max_time_window_days` (default 30 days). Requests exceeding the cap SHALL be rejected with HTTP 400. The window's lower bound SHALL prune the partition glob to relevant `date=<YYYY-MM-DD>/hour=<HH>/` directories so the executor never scans more than necessary.

#### Scenario: Default window is the last 1 hour
- **WHEN** a request omits both `since` and `until`
- **THEN** the system uses `since=<now-1h>`, `until=<now>`

#### Scenario: Window over the cap is rejected
- **WHEN** a request supplies a window > `crashler.read.max_time_window_days` (default 30 days)
- **THEN** the system responds with HTTP 400
- **AND** the response message names the configured cap

#### Scenario: Duration shorthand resolves correctly
- **WHEN** `?since=2h` arrives at 2026-05-09T15:00:00Z
- **THEN** the resolved window is `[2026-05-09T13:00:00Z, 2026-05-09T15:00:00Z]`

### Requirement: Cursor pagination

Every search endpoint SHALL support cursor-based pagination. The `limit` parameter SHALL default to 100 and SHALL be capped at `crashler.read.max_page_size` (default 1000). When more results exist than fit in the current page, the response SHALL include `_links.next` whose URL — when followed — returns the next page. The cursor SHALL be opaque to the client and SHALL encode the original query criteria together with the position. The cursor SHALL be HMAC-signed with `crashler.read.cursor_secret` so a client cannot forge a cursor that bypasses tenant scope or the time-window cap.

#### Scenario: First page response includes _links.next when more results exist
- **WHEN** a search returns 100 rows with at least one more available
- **THEN** the response carries `_links.next` whose URL contains an opaque cursor

#### Scenario: Following _links.next returns the next page
- **WHEN** a client GETs the URL in `_links.next` from a previous response
- **THEN** the system returns the next 100 rows
- **AND** every row in the second page is strictly later (in `(time_unix_nano, _row_id)` order) than the last row of the previous page

#### Scenario: Last page omits _links.next
- **WHEN** a search returns fewer rows than `limit`
- **THEN** the response does NOT carry `_links.next`

#### Scenario: Tampered cursor is rejected
- **WHEN** a request supplies a cursor whose HMAC signature does not match the configured secret
- **THEN** the system responds with HTTP 400 with a message indicating an invalid cursor

#### Scenario: Cursor cannot escape tenant scope
- **WHEN** tenant `acme` is presented with a cursor minted for tenant `widgets`
- **THEN** the system responds with HTTP 400 (the cursor signature was bound to the original tenant)

### Requirement: Compact JSON envelope for search responses

Search responses SHALL return JSON with the following shape:

```json
{
  "schemaId": "<signal>/v<version>",
  "rows": [ { /* one object per record */ } ],
  "_links": {
    "self": "<absolute or path-relative URL of this request>",
    "next": "<URL for the next page if more rows exist; omitted otherwise>"
  }
}
```

The `rows` array SHALL contain one JSON object per matching record. Object keys SHALL be the camelCase form of the on-disk Parquet column names (e.g., `time_unix_nano` → `timeUnixNano`). The `schemaId` SHALL match the value of the `_schema_id` column in the underlying Parquet rows.

#### Scenario: Search response carries schemaId, rows, and _links
- **WHEN** a search request succeeds
- **THEN** the response body has top-level fields `schemaId`, `rows`, and `_links`
- **AND** every entry of `rows` is a JSON object

#### Scenario: Empty result set
- **WHEN** a search matches zero rows
- **THEN** the response body's `rows` is `[]`
- **AND** the response body has no `_links.next`

#### Scenario: Column names are camelCase
- **WHEN** any search returns rows
- **THEN** keys like `timeUnixNano`, `traceIdHex`, `resourceServiceName` appear in each row
- **AND** keys like `time_unix_nano`, `trace_id_hex`, `resource_service_name` do NOT appear

### Requirement: HAL-style _links for navigation

Responses SHALL carry a `_links` object. Required link relations:

- `self` — the URL of the current request (always present on every response)
- `next` — the URL of the next page (present on search responses when more results exist; absent otherwise)

Per-row `_links` SHALL be present when a row carries an ID into another signal:

- `logs` row with non-null `traceIdHex` → `_links.trace = /v1/traces/<hex>`
- `logs` row with non-null `spanIdHex` → `_links.span = /v1/spans/<hex>`
- `traces` search row → `_links.trace = /v1/traces/<traceIdHex>`
- `metrics` row whose `exemplarsJson` contains at least one exemplar with a `traceId` → `_links.exemplars = /v1/traces/<first-exemplar-traceId-hex>`

By-ID responses (`/v1/traces/{traceId}`, `/v1/spans/{spanId}`) SHALL carry top-level `_links` with cross-signal relations:

- `_links.logs` — search URL for logs from this trace, scoped to the trace's time bounds
- `_links.metricsWithExemplars` — search URL for metrics referencing this trace's ID

#### Scenario: Search response carries self link
- **WHEN** any search response is returned
- **THEN** `_links.self` is present and equals the request URL (with the resolved time window made explicit)

#### Scenario: Logs row with trace ID has trace + span links
- **WHEN** a logs search row has non-null `traceIdHex` and non-null `spanIdHex`
- **THEN** that row's `_links.trace` is `/v1/traces/<traceIdHex>`
- **AND** that row's `_links.span` is `/v1/spans/<spanIdHex>`

#### Scenario: Logs row without trace ID has no trace link
- **WHEN** a logs search row has null `traceIdHex`
- **THEN** that row's `_links` has no `trace` rel
- **AND** the row may still carry other unrelated `_links`

#### Scenario: Trace by ID response carries cross-signal links
- **WHEN** `GET /v1/traces/<id>` returns a tree
- **THEN** the response's top-level `_links.logs` is the corresponding `/v1/logs?traceId=<id>&since=...&until=...` URL
- **AND** the response's top-level `_links.metricsWithExemplars` is the corresponding `/v1/metrics?exemplarTraceId=<id>&since=...&until=...` URL

### Requirement: Validated, typed criteria

Each search endpoint SHALL accept a documented, fixed set of named URL parameters. Common to every search endpoint:

- `since`, `until` — time window
- `service` — equality on `resource_service_name`
- `environment` — equality on `resource_deployment_environment`
- `host` — equality on `resource_host_name`
- `limit` — page size
- `cursor` — pagination token

Unknown parameters SHALL be rejected with HTTP 400 listing the supported ones. Filter values SHALL be type-checked: integer params reject non-integers, enum params (`severityText`, `kind`, `metricType`, `statusCode`) reject values outside the documented set.

#### Scenario: Unknown parameter rejected
- **WHEN** `GET /v1/logs?service=foo&banana=yes&since=1h`
- **THEN** the system responds with HTTP 400
- **AND** the message names `banana` as the unknown parameter and lists the supported ones

#### Scenario: Type-mismatched parameter rejected
- **WHEN** `GET /v1/logs?since=yesterday`
- **THEN** the system responds with HTTP 400 with a parse-error message naming `since`

#### Scenario: Enum-mismatched parameter rejected
- **WHEN** `GET /v1/traces?kind=BANANA&since=1h`
- **THEN** the system responds with HTTP 400 with a message listing supported kind values

### Requirement: Compute engine with auto-detection

The system SHALL execute read queries via either embedded DuckDB (shell-out to a `duckdb` binary) or a flow-php native scanner. The choice SHALL be made at boot:

- If `crashler.read.compute_engine=duckdb`, use DuckDB; fail boot if the binary is missing.
- If `crashler.read.compute_engine=flow-php`, use flow-php.
- If `crashler.read.compute_engine=auto` (default), prefer DuckDB if present on `PATH` (or at the path in `CRASHLER_DUCKDB_BIN`), otherwise fall back to flow-php.

The selected engine SHALL be visible in `bin/console debug:container` output. Both engines SHALL produce results that are equivalent for the same input (subject to floating-point ordering); the only operational difference is performance.

#### Scenario: DuckDB binary present at boot
- **WHEN** the kernel boots with `compute_engine=auto` and a DuckDB binary is on PATH
- **THEN** the active executor is the DuckDB executor

#### Scenario: DuckDB binary missing at boot
- **WHEN** the kernel boots with `compute_engine=auto` and no DuckDB binary is reachable
- **THEN** the active executor is the flow-php executor

#### Scenario: DuckDB forced but missing fails boot
- **WHEN** the kernel boots with `compute_engine=duckdb` and no DuckDB binary is reachable
- **THEN** boot fails with a clear error naming the missing binary and the configured engine

### Requirement: Error response shape

Error responses SHALL carry HTTP status codes per their meaning and a JSON body containing at minimum a top-level `message` field:

- 400 — bad criteria, malformed time window, time window over the cap, unknown parameter, tampered cursor
- 401 — missing or invalid bearer token
- 404 — by-ID lookup against an ID not present in the configured search window
- 415 — request carries an unsupported `Content-Type` or `Accept` value
- 500 — executor failure (DuckDB nonzero exit, file-system error, OOM)

Error bodies SHALL be valid JSON. Error bodies SHALL NOT leak internal stack traces, file paths under `var/`, or DuckDB internal error messages verbatim.

#### Scenario: Bad criteria error body
- **WHEN** any 4xx response is returned
- **THEN** the response body is valid JSON
- **AND** the body has a top-level `message` field describing the error

#### Scenario: Internal failure error body
- **WHEN** a 5xx response is returned
- **THEN** the body's `message` describes the error in operator-friendly terms
- **AND** the body does NOT contain a stack trace, an absolute filesystem path, or raw DuckDB output

### Requirement: Bounded resource consumption per request

A single read request SHALL NOT exceed the following operational limits:

- Result row count ≤ `crashler.read.max_page_size` (default 1000)
- Time window ≤ `crashler.read.max_time_window_days` (default 30 days)
- Underlying Parquet scan SHALL be pruned to the partition directories within the window

#### Scenario: Limit exceeding max_page_size is clamped or rejected
- **WHEN** `?limit=10000` arrives and `max_page_size` is 1000
- **THEN** the system responds with HTTP 400 OR silently clamps to 1000 and notes the clamp in `_links.self`
- **AND** the response never carries more than 1000 rows
