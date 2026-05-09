## ADDED Requirements

### Requirement: HTTP read endpoints under /v1/

The system SHALL expose read endpoints alongside the existing OTLP write endpoints, sharing the same Symfony firewall, the same Bearer-token auth, and the same tenant model. Read endpoints SHALL be declared as API Platform `#[ApiResource]` operations with `routePrefix: /v1`. Read traffic SHALL use HTTP `GET`. Read endpoints SHALL be:

- `GET /v1/logs` — Log GetCollection (search logs)
- `GET /v1/traces` — Trace GetCollection (search traces)
- `GET /v1/traces/{traceId}` — Trace Get (one trace tree by ID)
- `GET /v1/spans/{spanId}` — Span Get (one span by ID)
- `GET /v1/metrics` — Metric GetCollection (search metric data-points)

#### Scenario: GET on a write-only path is rejected
- **WHEN** a request arrives at a path that has both a POST (write) and a GET (read) handler — `/v1/logs`, `/v1/traces`, `/v1/metrics` — and the verb is GET
- **THEN** the request is routed to the read handler, not the write handler
- **AND** a missing/invalid bearer SHALL still return 401 just as it does on POST

#### Scenario: GET requires no body
- **WHEN** a `GET /v1/logs?...` request arrives with `Content-Length > 0`
- **THEN** the system responds with HTTP 415 with a message stating that read endpoints take no body

### Requirement: Bearer-token authentication and tenant scoping

Every read endpoint SHALL require a valid Bearer token that resolves to a known tenant via the existing `tenants` capability. The tenant slug derived from the token SHALL bound the underlying file glob: a query for tenant `acme` SHALL ONLY read files under `<storage-root>/<signal>/acme/`. Tenant escape SHALL be impossible by construction (the slug enters as a path segment, not as a SQL or filter argument). State providers SHALL receive the authenticated `IngestUser` from Symfony Security and use its tenant slug exclusively.

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
- **THEN** the scanner's input glob resolves under `<storage-root>/logs/acme/` exclusively
- **AND** files in `<storage-root>/logs/<other-tenant>/` are not read

### Requirement: Mandatory bounded time window

Every search endpoint SHALL accept a time window via either:

- both `since` and `until` parameters (RFC3339 strings or unix-nano integer-as-string), or
- a `since=<duration>` shorthand (e.g. `1h`, `15m`, `7d`) implying `until=<now>`,
- or no parameters at all, in which case the implicit window is "the last 1 hour".

The resolved `[since, until]` window SHALL be ≤ `crashler.read.max_time_window_days` (default 30 days). Requests exceeding the cap SHALL be rejected with HTTP 400. Mixing absolute `until` with shorthand `since=<duration>` SHALL be rejected with HTTP 400 ("mixed time semantics"). The window's lower bound SHALL prune the partition glob to relevant `date=<YYYY-MM-DD>/hour=<HH>/` directories so the scanner never opens more files than necessary.

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

#### Scenario: Mixed time semantics rejected
- **WHEN** `?since=2h&until=2026-05-09T15:00:00Z` is sent
- **THEN** the system responds with HTTP 400 with a message about mixed time semantics

### Requirement: Cursor pagination integrated with API Platform

Every search endpoint SHALL support cursor-based pagination via API Platform's pagination contract. The `limit` parameter SHALL default to 100 and SHALL be capped at `crashler.read.max_page_size` (default 1000). When more results exist than fit in the current page, the response SHALL include a hypermedia next affordance rendered into the requested format (`hydra:next` for Hydra, `_links.next` for HAL/compact JSON, `links.next` for JSON:API) whose URL — when followed — returns the next page. The cursor SHALL be opaque to the client and SHALL encode the original query criteria (filters + resolved absolute `since`/`until` instants + ordering) together with the position. The cursor SHALL be HMAC-signed with `crashler.read.cursor_secret` so a client cannot forge a cursor that bypasses tenant scope or the time-window cap.

#### Scenario: First page response includes next affordance when more results exist
- **WHEN** a search returns 100 rows with at least one more available
- **THEN** the response carries a next affordance in the requested format whose URL contains an opaque cursor

#### Scenario: Following the next link returns the next page
- **WHEN** a client GETs the URL in the next affordance from a previous response
- **THEN** the system returns the next 100 rows
- **AND** every row in the second page is strictly later (in `(time_unix_nano, row_id)` order) than the last row of the previous page

#### Scenario: Last page omits next affordance
- **WHEN** a search returns fewer rows than `limit`
- **THEN** the response does NOT carry a next affordance

#### Scenario: Tampered cursor is rejected
- **WHEN** a request supplies a cursor whose HMAC signature does not match the configured secret
- **THEN** the system responds with HTTP 400 with a message indicating an invalid cursor

#### Scenario: Cursor cannot escape tenant scope
- **WHEN** tenant `acme` is presented with a cursor minted for tenant `widgets`
- **THEN** the system responds with HTTP 400 (the cursor signature was bound to the original tenant)

### Requirement: Content-negotiated wire formats

Search responses SHALL be content-negotiated based on the request's `Accept` header. The system SHALL support at minimum:

- `application/ld+json` (Hydra; default when no Accept header is sent)
- `application/hal+json` (HAL)
- `application/json` (compact)
- `application/vnd.api+json` (JSON:API)

The Trace.Get item operation SHALL additionally support `application/otlp+json` returning OTLP `ResourceSpans`-shaped JSON wrapped with a `_links` block (see logs-query/traces-query/metrics-query specs for per-format link rendering). All formats SHALL be derived from the same Resource declaration; clients receive equivalent data in different shapes. Unsupported `Accept` values SHALL be rejected with HTTP 415.

The compact JSON envelope SHALL have the shape:

```json
{
  "schemaId": "<signal>/v<version>",
  "rows": [ { /* one object per record, camelCase keys */ } ],
  "_links": {
    "self": "<URL of this request with resolved absolute time window>",
    "next": "<URL for the next page if more rows exist; omitted otherwise>"
  }
}
```

JSON object keys SHALL be the camelCase form of the on-disk Parquet column names (e.g., `time_unix_nano` → `timeUnixNano`). The `schemaId` value SHALL match the on-disk `_schema_id` column.

#### Scenario: Default format is Hydra
- **WHEN** a search request arrives without an `Accept` header
- **THEN** the response Content-Type is `application/ld+json`
- **AND** the response body has top-level fields `@context`, `@id`, `@type` (= `"hydra:Collection"`), and `hydra:member`

#### Scenario: Compact JSON requested
- **WHEN** a search request includes `Accept: application/json`
- **THEN** the response Content-Type is `application/json`
- **AND** the response body has top-level fields `schemaId`, `rows`, and `_links`

#### Scenario: HAL requested
- **WHEN** a search request includes `Accept: application/hal+json`
- **THEN** the response Content-Type is `application/hal+json`
- **AND** the response body has `_links` with `self` (and optionally `next`) entries shaped as `{href: "..."}`
- **AND** the rows are nested under `_embedded`

#### Scenario: Unsupported Accept value rejected
- **WHEN** a request includes `Accept: text/plain`
- **THEN** the system responds with HTTP 415

#### Scenario: Empty result set
- **WHEN** a search matches zero rows
- **THEN** the response is HTTP 200 (search endpoints never 404)
- **AND** the rows collection (`rows` / `hydra:member` / `data`) is empty
- **AND** the response has no next affordance

#### Scenario: Column names are camelCase
- **WHEN** any search returns rows in any format
- **THEN** keys like `timeUnixNano`, `traceIdHex`, `resourceServiceName` appear in each row
- **AND** keys like `time_unix_nano`, `trace_id_hex`, `resource_service_name` do NOT appear

### Requirement: Hypermedia for navigation, rendered per format

Every response SHALL carry navigation affordances appropriate to the requested format:

- **`self`** — the URL of the current request with the resolved absolute time window (always present)
- **`next`** — the URL of the next page (present on search responses with more results; absent on the last page)

Per-row affordances SHALL be present when a row carries an ID into another signal:

- `Log` row with non-null `traceIdHex` → `trace` link to `/v1/traces/<hex>`
- `Log` row with non-null `spanIdHex` → `span` link to `/v1/spans/<hex>`
- `Trace` search row → `trace` link to `/v1/traces/<traceIdHex>`
- `Metric` row whose `exemplarsJson` carries at least one exemplar with a `traceId` → `exemplars` link to `/v1/traces/<first-exemplar-traceId-hex>`

By-ID responses (`/v1/traces/{traceId}`, `/v1/spans/{spanId}`) SHALL carry top-level cross-signal affordances:

- Trace.Get: `logs` (logs from this trace, scoped to the trace's time bounds), `metricsWithExemplars` (metrics referencing this trace's ID)
- Span.Get: `trace` (the parent trace), `logs` (logs from that trace, narrowed to this span)

The link relations are the same across formats; the rendering differs (Hydra `hydra:Operation`, HAL `_links.<rel>`, compact `_links.<rel>`, JSON:API `relationships.<rel>.links.related`).

#### Scenario: Search response carries self affordance
- **WHEN** any search response is returned in any format
- **THEN** the response carries a `self` affordance whose URL equals the request URL with the resolved absolute time window

#### Scenario: Logs row with trace ID has trace + span affordances
- **WHEN** a Log search row has both `traceIdHex` and `spanIdHex` populated
- **THEN** that row's affordances include `trace = /v1/traces/<traceIdHex>` and `span = /v1/spans/<spanIdHex>`

#### Scenario: Logs row without trace ID omits trace affordance
- **WHEN** a Log search row has null `traceIdHex`
- **THEN** that row's affordances do NOT contain a `trace` rel
- **AND** that row's affordances do NOT contain a `span` rel even if `spanIdHex` somehow exists

#### Scenario: Trace by ID response carries cross-signal affordances
- **WHEN** `GET /v1/traces/<id>` returns a tree
- **THEN** the response carries `logs = /v1/logs?traceId=<id>&since=...&until=...` and `metricsWithExemplars = /v1/metrics?exemplarTraceId=<id>&since=...&until=...`

### Requirement: Validated, typed criteria via #[ApiFilter]

Each search endpoint SHALL expose a documented set of criteria as `#[ApiFilter]` attributes on the corresponding Resource class. Common criteria across all signals:

- `since`, `until` — time window
- `service` — equality on `resource_service_name`
- `environment` — equality on `resource_deployment_environment`
- `host` — equality on `resource_host_name`
- `limit` — page size
- `cursor` — pagination token

Per-signal criteria are declared in the per-signal specs. Unknown query parameters SHALL be rejected with HTTP 400 listing the supported ones for the endpoint. Filter values SHALL be type-checked: integer params reject non-integers, enum params (`severityText`, `kind`, `metricType`, `statusCode`) reject values outside the documented set. The full predicate compilation table is normative in design.md (D11).

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

#### Scenario: Repeated query parameter rejected
- **WHEN** `GET /v1/logs?service=foo&service=bar&since=1h`
- **THEN** the system responds with HTTP 400 with a message about the repeated parameter

### Requirement: Compute via streaming flow-php Parquet scanner

The system SHALL execute read queries via a streaming `App\Read\Compute\ParquetScanner` that reads Parquet files row-by-row using flow-php's `Reader`. Files SHALL be iterated in ULID order (creation-time order). Filters SHALL be evaluated as typed predicates in tier order (cheap top-level columns before expensive JSON-string scans) so wide queries fail-fast on the cheap predicates. Where flow-php exposes per-row-group min/max statistics for typed columns, the scanner SHALL skip row groups whose statistics disjoint from the predicate. Per-request execution time SHALL be bounded by `crashler.read.execution_timeout_seconds` (default 10); when exceeded, the system SHALL respond with HTTP 504 and a message asking the client to narrow the time window or filters.

There SHALL NOT be a configurable choice of compute engine; the scanner is the only execution path in v1. (A future change may introduce alternatives behind a `ScansParquet` interface — that is not a v1 contract.)

#### Scenario: Scanner emits matching rows in ULID order
- **WHEN** a search request resolves to a partition with three Parquet files written at distinct times
- **THEN** the scanner reads them in ULID-ascending order
- **AND** the response's row collection reflects that order (older first within a partition)

#### Scenario: Scanner stops early when limit reached
- **WHEN** a partition contains 10 000 matching rows and `limit=100`
- **THEN** the scanner reads at most enough row groups to surface 100 rows
- **AND** does not continue scanning the rest of the partition

#### Scenario: Tier-ordered predicate evaluation short-circuits
- **WHEN** a request carries `service=checkout` AND `attribute.exception.type=Boom`
- **AND** a row's `resourceServiceName` is `payments` (mismatching `service`)
- **THEN** that row is rejected by the cheap `ColumnEquals('resource_service_name', ...)` predicate
- **AND** the expensive `JsonAttributeEquals` on `attributes_json` is NOT evaluated for that row

#### Scenario: Row-group statistics push down for numeric predicates
- **WHEN** a partition's row group has `max(severity_number) = 9` and the request carries `severityNumberMin=17`
- **THEN** the scanner skips that row group entirely
- **AND** does not iterate rows inside it

#### Scenario: Execution timeout returns 504
- **WHEN** a request takes longer than `crashler.read.execution_timeout_seconds` to materialise the response
- **THEN** the system responds with HTTP 504
- **AND** the message asks the client to narrow filters or reduce the time window

### Requirement: Error response shape

Error responses SHALL carry HTTP status codes per their meaning and a JSON body containing at minimum a top-level `message` field:

- 400 — bad criteria, malformed time window, time window over the cap, unknown parameter, repeated parameter, mixed time semantics, malformed path ID, tampered cursor, cursor minted for a different tenant
- 401 — missing or invalid bearer token
- 404 — by-ID lookup against an ID not present in the configured search window
- 415 — request carries an unsupported `Content-Type` (read endpoints take no body), unsupported `Accept` value
- 500 — scanner failure (file-system error, corrupted Parquet file, OOM)
- 504 — execution timeout (per-request scan exceeded `crashler.read.execution_timeout_seconds`)

Error bodies SHALL be valid JSON. Error bodies SHALL NOT leak internal stack traces, file paths under `var/`, or low-level library error messages verbatim.

#### Scenario: Bad criteria error body
- **WHEN** any 4xx response is returned
- **THEN** the response body is valid JSON
- **AND** the body has a top-level `message` field describing the error

#### Scenario: Internal failure error body
- **WHEN** a 5xx response is returned
- **THEN** the body's `message` describes the error in operator-friendly terms
- **AND** the body does NOT contain a stack trace, an absolute filesystem path, or raw library output

### Requirement: OpenAPI 3 specification

The system SHALL expose an auto-generated OpenAPI 3 specification at `/api/docs.json` and a Swagger UI at `/api/docs`, both derived from the Resource declarations. The spec SHALL document:

- Every read path (`/v1/logs`, `/v1/traces`, `/v1/traces/{traceId}`, `/v1/spans/{spanId}`, `/v1/metrics`)
- Every supported filter on every search endpoint, with name, type, and description
- The bearer-token security scheme (referencing the existing `IngestTokenAuthenticator`)
- The available output formats (jsonld, hal, json, jsonapi, and otlp+json on Trace.Get)
- The cursor pagination model
- The error response shape

The spec SHALL be valid against the OpenAPI 3.1 schema. CI SHALL verify the spec's correctness on every build.

#### Scenario: OpenAPI spec is reachable
- **WHEN** an unauthenticated client GETs `/api/docs.json`
- **THEN** the response status is 200
- **AND** the body parses as a valid OpenAPI 3.1 document

#### Scenario: All read paths are documented
- **WHEN** the OpenAPI spec is loaded
- **THEN** the `paths` object contains entries for `/v1/logs`, `/v1/traces`, `/v1/traces/{traceId}`, `/v1/spans/{spanId}`, `/v1/metrics`

#### Scenario: Filters are documented
- **WHEN** the OpenAPI spec is loaded
- **THEN** the `/v1/logs` GET operation lists every documented log filter (`service`, `severityNumberMin`, `traceId`, etc.) under `parameters`

#### Scenario: Bearer auth is documented
- **WHEN** the OpenAPI spec is loaded
- **THEN** `components.securitySchemes` declares a bearer-token scheme
- **AND** every read operation references it

### Requirement: Bounded resource consumption per request

A single read request SHALL NOT exceed the following operational limits:

- Result row count ≤ `crashler.read.max_page_size` (default 1000)
- Time window ≤ `crashler.read.max_time_window_days` (default 30 days)
- Per-request execution time ≤ `crashler.read.execution_timeout_seconds` (default 10 seconds)
- Underlying Parquet scan SHALL be pruned to the partition directories within the window

#### Scenario: Limit exceeding max_page_size is clamped or rejected
- **WHEN** `?limit=10000` arrives and `max_page_size` is 1000
- **THEN** the system responds with HTTP 400 OR silently clamps to 1000 and notes the clamp in the `self` affordance
- **AND** the response never carries more than 1000 rows

### Requirement: Caching headers and response compression

Read responses SHALL set `Cache-Control: no-store, private` on every response (defends against browser-private-cache leakage when a future UI is added). Read responses SHALL be gzipped when the request includes `Accept-Encoding: gzip`. ETag and conditional GET (`If-None-Match` / 304) SHALL NOT be implemented in v1.

#### Scenario: Cache-Control header set on success
- **WHEN** any 2xx response is returned
- **THEN** the response carries `Cache-Control: no-store, private`

#### Scenario: Gzip when accepted
- **WHEN** a request carries `Accept-Encoding: gzip` and the response body is non-trivial in size
- **THEN** the response carries `Content-Encoding: gzip` and the body is gzip-compressed
