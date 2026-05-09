## Purpose

Defines the HTTP semantics shared by all OTLP read endpoints under `/v1/`. Read endpoints are declared as API Platform `#[ApiResource]` operations and share the existing Bearer-token authentication and tenant model used by the OTLP write path. Every search request is bounded by a mandatory time window and capped page size, paginated via HMAC-signed opaque cursors, content-negotiated across Hydra/HAL/compact-JSON/JSON:API, and executed by a streaming flow-php Parquet scanner. The OpenAPI 3 specification auto-generated from the Resource declarations is the canonical consumer contract.
## Requirements
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

Per-signal criteria are declared in the per-signal specs. Unknown query parameters SHALL be rejected with HTTP 400 listing the supported ones for the endpoint. Filter values SHALL be type-checked: integer params reject non-integers, enum params (`severityText`, `kind`, `metricType`, `statusCode`, `aggregationTemporality`) reject values outside the documented set. Schema-level violations (enum, pattern) — surfaced by API Platform's parameter validator — return **HTTP 422 (Unprocessable Entity)**, AP's standard for such cases; state-provider-level violations (semantic constraints not expressible in JSON schema, e.g. wildcard not supported in v1 `metricName`) return **HTTP 400**. Both response bodies carry a JSON `message` field with operator-actionable detail. The full predicate compilation table is normative in design.md (D11).

Repeated occurrences of any non-`attribute` query parameter — for example `service=foo&service=bar` — SHALL be rejected with HTTP 400. The `attribute.<key>` family is the documented exception: distinct keys SHALL compose with logical AND up to a per-request cap (`crashler.read.max_attribute_filters`, default 5). Repeating the *same* attribute key (`attribute.exception.type=A&attribute.exception.type=B`) remains a "repeated query parameter" violation and SHALL be rejected with HTTP 400. Requests carrying more than the cap of distinct `attribute.<key>` filters SHALL be rejected with HTTP 400 and a message naming the cap.

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

#### Scenario: Repeated non-attribute query parameter rejected
- **WHEN** `GET /v1/logs?service=foo&service=bar&since=1h`
- **THEN** the system responds with HTTP 400 with a message about the repeated parameter

#### Scenario: Multiple distinct attribute filters compose with AND
- **WHEN** `GET /v1/logs?attribute.exception.type=RuntimeException&attribute.http.method=POST&since=1h`
- **THEN** the request is accepted
- **AND** every returned row's decoded `attributesJson` carries entries for both `exception.type=RuntimeException` and `http.method=POST`

#### Scenario: Same attribute key repeated rejected
- **WHEN** `GET /v1/logs?attribute.exception.type=A&attribute.exception.type=B&since=1h`
- **THEN** the system responds with HTTP 400 with a message about the repeated parameter `attribute.exception.type`

#### Scenario: Attribute filter cap exceeded
- **WHEN** the request carries six or more distinct `attribute.<key>` parameters and the configured cap is 5
- **THEN** the system responds with HTTP 400 with a message naming the cap and asking the client to narrow the filters

### Requirement: Compute via streaming flow-php Parquet scanner

The system SHALL execute read queries via a streaming `App\Read\Compute\ParquetScanner` that reads Parquet files row-by-row using flow-php's `Reader`. Files SHALL be iterated in ULID order (creation-time order). Filters SHALL be evaluated as typed predicates in tier order (cheap top-level columns before expensive JSON-string scans) so wide queries fail-fast on the cheap predicates.

For each Parquet file, BEFORE iterating rows the scanner SHALL read the file's row-group metadata via flow-php's `ParquetFile::metadata()->rowGroups()` and, for every active numeric predicate (`ColumnInRange`, `ColumnGreaterEqual`, or numeric `ColumnEquals`), evaluate the predicate against the per-row-group `min`/`max` statistics of the referenced column. A row group SHALL be skipped — not opened for row iteration — when at least one numeric predicate refutes it (i.e. its `[min, max]` interval is disjoint from the predicate's accepted range). When statistics are absent, when the column type does not carry stats, or when the predicate references a column not present in the row group's schema, the row group SHALL fall through to row-by-row evaluation. String predicates (prefix, suffix, JSON substring, JSON-attribute equals) and any other non-numeric predicate SHALL NOT be applied at the row-group skip step in v1.

The scanner SHALL expose, on the `ScanResult` value object returned to state providers, two integer counters: `groupsScanned` (row groups whose data pages were materialised) and `groupsSkipped` (row groups elided by the metadata check). These fields SHALL be observable by component tests and SHALL NOT be surfaced in the HTTP response body.

Per-request execution time SHALL be bounded by `crashler.read.execution_timeout_seconds` (default 10); when exceeded, the system SHALL respond with HTTP 504 and a message asking the client to narrow the time window or filters.

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

#### Scenario: Row group skipped via min/max push-down for `>=` predicate
- **WHEN** a partition's row group reports `max(severity_number) = 9` in its statistics and the request carries `severityNumberMin=17`
- **THEN** the scanner skips that row group entirely
- **AND** does not iterate rows inside it
- **AND** the returned `ScanResult.groupsSkipped` is incremented by one for that group

#### Scenario: Row group skipped via min/max push-down for range predicate
- **WHEN** a partition's row group reports `[min, max]` for `time_unix_nano` that falls entirely before the request's `since` lower bound
- **THEN** the scanner skips the row group
- **AND** `groupsSkipped` reflects the skip

#### Scenario: Row group scanned when statistics are missing
- **WHEN** a partition's row group reports null `min`/`max` for the predicate's column (writer did not record statistics, or column type does not support them)
- **THEN** the scanner falls through and evaluates the predicate row-by-row inside that group
- **AND** `groupsScanned` is incremented for that group
- **AND** the response is bit-identical to a scan that did not attempt push-down

#### Scenario: String predicates do not trigger push-down
- **WHEN** a request carries only a `service=checkout` filter and no numeric predicates beyond the time window
- **THEN** the scanner SHALL NOT skip any row group on account of the string predicate
- **AND** any row groups elided are elided only by the time-window numeric predicate

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

The system SHALL expose an auto-generated OpenAPI 3 specification at `/docs.jsonopenapi` and a Swagger UI at `/docs`, both derived from the Resource declarations. The spec SHALL document:

- Every read path (`/v1/logs`, `/v1/traces`, `/v1/traces/{traceId}`, `/v1/spans/{spanId}`, `/v1/metrics`)
- Every supported filter on every search endpoint, with name, type, and description
- The bearer-token security scheme (referencing the existing `IngestTokenAuthenticator`)
- The available output formats (jsonld, hal, json, jsonapi, and otlp+json on Trace.Get)
- The cursor pagination model
- The error response shape

The spec SHALL be valid against the OpenAPI 3.1 schema. CI SHALL verify the spec's correctness on every build.

#### Scenario: OpenAPI spec is reachable
- **WHEN** an unauthenticated client GETs `/docs.jsonopenapi`
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

### Requirement: POST /v1/<signal>/search for complex criteria

The system SHALL expose, in addition to the GET search endpoints, three POST search endpoints:

- `POST /v1/logs/search`
- `POST /v1/traces/search`
- `POST /v1/metrics/search`

Each SHALL share the firewall, Bearer-token authentication, tenant scoping, time-window cap, page-size cap, and execution-timeout governance of the corresponding GET endpoint. Each SHALL be exposed via either an API Platform `#[Post]` operation on the corresponding Resource class OR a plain Symfony controller with `#[Route]` that owns body parsing, predicate-tree compilation, and content-negotiated response shaping (the latter mirrors the existing `ReadTraceController` / `ReadSpanController` precedent for non-collection-shaped responses under `/v1/`). Body shapes, validation, and response semantics SHALL be identical regardless of which option is used.

The request body SHALL be JSON of shape:

```jsonc
{
  "since":  "1h" | "<RFC3339>" | "<unix-nano>",
  "until":  null | "<RFC3339>" | "<unix-nano>",
  "limit":  100,                  // optional; same default and cap as GET
  "cursor": null | "<opaque>",    // optional; mutually exclusive with the other fields once set
  "criteria": <PredicateNode>     // see "Predicate-tree DSL" below
}
```

Body content type SHALL be `application/json`; other content types SHALL be rejected with HTTP 415. Bodies larger than `crashler.read.post_search.max_body_bytes` (default 64 KiB) SHALL be rejected with HTTP 413. Malformed JSON SHALL be rejected with HTTP 400.

Response shape, per-row hypermedia, content negotiation across `application/ld+json` / `application/hal+json` / `application/json` / `application/vnd.api+json`, cursor pagination, error envelope, and Cache-Control / Vary headers SHALL be identical to the corresponding GET search endpoint.

#### Scenario: Successful POST search returns same shape as GET
- **WHEN** `POST /v1/logs/search` is invoked with a valid bearer, `Accept: application/ld+json`, and a body containing `since` and a simple `criteria`
- **THEN** the response status is 200
- **AND** the response body is Hydra-shaped exactly as a `GET /v1/logs?...&since=...` response would be
- **AND** every row carries the same `_links.trace` / `_links.span` affordances as the GET response

#### Scenario: Unsupported Content-Type rejected
- **WHEN** `POST /v1/logs/search` arrives with `Content-Type: text/plain`
- **THEN** the system responds with HTTP 415

#### Scenario: Oversize body rejected
- **WHEN** the request body exceeds `crashler.read.post_search.max_body_bytes`
- **THEN** the system responds with HTTP 413 with a message naming the configured cap

#### Scenario: Malformed JSON body rejected
- **WHEN** the body is not valid JSON
- **THEN** the system responds with HTTP 400 with a message indicating a JSON parse error

### Requirement: Predicate-tree DSL for POST search

The `criteria` field of a POST search body SHALL be a recursive JSON tree with the following node shapes. Every node SHALL declare its kind by the presence of a discriminator key:

- `{"all": [<PredicateNode>, ...]}` — AND combinator. Empty `all` array SHALL be rejected with HTTP 400.
- `{"any": [<PredicateNode>, ...]}` — OR combinator. Empty `any` array SHALL be rejected with HTTP 400.
- `{"not": <PredicateNode>}` — NOT combinator. Single child only.
- `{"column": "<col>", "op": "<op>", "value": <v>}` — typed-column leaf. `<op>` ∈ {`eq`, `ne`, `gte`, `lte`, `in`, `prefix`, `suffix`}. `value` is `<v>` for non-`in` ops, an array of values for `in`. Allowed `<col>` per signal SHALL be the same column set the GET endpoint accepts.
- `{"attribute": "<key>", "op": "eq", "value": <v>}` — attribute-walk leaf. `<key>` is the attribute key inside the signal's `attributes_json`. Only `eq` SHALL be supported in v1.
- `{"body": "contains", "value": "<substring>"}` — body substring leaf, logs only. Other signals SHALL reject this leaf shape with HTTP 400.

The compiler SHALL translate the tree into the existing predicate classes plus two new combinators (`Any`, `Not`). `in` SHALL compile to an `Any` of `ColumnEquals` leaves. `ne` SHALL compile to a `Not(ColumnEquals(...))`. The compiled predicate tree SHALL be evaluated by the existing `App\Read\Compute\ParquetScanner`. Tier ordering for combinators SHALL be defined as: `Any.tier == max(child.tier)`, `Not.tier == child.tier`. Leaves keep the tier their corresponding GET-side predicate already declares.

Maximum tree depth SHALL be 8. Maximum `in`-list length SHALL be 256. Maximum count of `attribute` leaves SHALL be `crashler.read.max_attribute_filters` (the same cap shared with the GET endpoint).

#### Scenario: AND-of-equals translates to existing predicate composition
- **WHEN** the `criteria` is `{"all": [{"column": "resource_service_name", "op": "eq", "value": "checkout"}, {"column": "severity_number", "op": "gte", "value": 17}]}`
- **THEN** the compiled predicate list is `ColumnEquals('resource_service_name', 'checkout')` AND `ColumnGreaterEqual('severity_number', 17)`
- **AND** the response is identical to `GET /v1/logs?service=checkout&severityNumberMin=17&since=...`

#### Scenario: OR composes alternatives
- **WHEN** the `criteria` is `{"any": [{"column": "resource_service_name", "op": "eq", "value": "checkout"}, {"column": "resource_service_name", "op": "eq", "value": "payments"}]}`
- **THEN** the response includes rows whose `resourceServiceName` is `checkout` OR `payments`
- **AND** rows whose service is anything else are excluded

#### Scenario: NOT excludes
- **WHEN** the `criteria` is `{"not": {"column": "resource_service_name", "op": "eq", "value": "internal"}}`
- **THEN** the response includes only rows whose `resourceServiceName` is not `internal`

#### Scenario: IN-list compiles to OR of equals
- **WHEN** the `criteria` is `{"column": "trace_id_hex", "op": "in", "value": ["aaaa…", "bbbb…", "cccc…"]}`
- **THEN** every returned row's `traceIdHex` is one of the three listed IDs

#### Scenario: Empty AND/OR rejected
- **WHEN** the `criteria` is `{"all": []}` or `{"any": []}`
- **THEN** the system responds with HTTP 400 with a message that combinators require at least one child

#### Scenario: Excessive nesting rejected
- **WHEN** the `criteria` tree exceeds depth 8
- **THEN** the system responds with HTTP 400 with a message naming the depth cap

#### Scenario: Excessive IN-list rejected
- **WHEN** any `in` leaf carries more than 256 values
- **THEN** the system responds with HTTP 400 with a message naming the per-list cap

#### Scenario: Body leaf rejected on non-logs signal
- **WHEN** `POST /v1/traces/search` carries a `criteria` containing a `{"body": "contains", "value": "..."}` leaf
- **THEN** the system responds with HTTP 400 with a message that body filters are logs-only

#### Scenario: Unknown column rejected
- **WHEN** a `column` leaf names a column not in the signal's allowed set
- **THEN** the system responds with HTTP 400 with a message naming the unknown column and listing the allowed ones

#### Scenario: Unknown operator rejected
- **WHEN** any leaf carries an `op` outside the documented set
- **THEN** the system responds with HTTP 400 with a message naming the unknown operator and listing the supported ones

### Requirement: Cursors are method-bound via a criteria digest

The HMAC-signed cursor format SHALL gain a `criteria_digest` field. Cursors minted by the GET state providers SHALL set `criteria_digest = null`. Cursors minted by the POST search processors SHALL set `criteria_digest` to a SHA-256 hex of the canonicalised `criteria` JSON.

On follow-up POST search, the processor SHALL recompute the digest from the new request body's canonicalised criteria and SHALL reject the request with HTTP 400 if the digest differs from the cursor's. POST search SHALL reject cursors with `criteria_digest = null` (those minted by GET) with HTTP 400. GET state providers SHALL reject cursors with non-null `criteria_digest` (those minted by POST) with HTTP 400.

Canonicalisation SHALL be deterministic: object keys sorted ascending, no whitespace, integers preserved as integers, NUL characters and BOM rejected. The HMAC signature SHALL cover the digest field along with the existing tenant / window / position fields.

#### Scenario: POST cursor round-trips to a follow-up POST with the same body
- **WHEN** a POST search response carries a next affordance and the client follows it by POSTing the original body with the supplied `cursor`
- **THEN** the response is the next page in the same query

#### Scenario: GET cursor rejected on POST search
- **WHEN** a `POST /v1/logs/search` body carries a `cursor` minted by `GET /v1/logs`
- **THEN** the system responds with HTTP 400 with a message indicating cursor/method mismatch

#### Scenario: POST cursor rejected on GET search
- **WHEN** `GET /v1/logs?cursor=<post-cursor>` is sent
- **THEN** the system responds with HTTP 400 with a message indicating cursor/method mismatch

#### Scenario: Cursor reused with mutated criteria rejected
- **WHEN** the client follows a POST cursor but submits a different `criteria` on the next request
- **THEN** the system responds with HTTP 400 with a message indicating the criteria have changed and the cursor is no longer valid

### Requirement: OpenAPI document carries examples on every read operation parameter

The auto-generated OpenAPI 3.1 document SHALL carry, for every read API operation, an `example` (or non-empty `examples` map) on every documented query parameter. The example value SHALL be a realistic value resembling on-disk OTLP shapes (real-shape hex IDs, OTLP-aligned severity numbers, real service names, RFC3339 or unix-nano timestamps). Placeholder values such as `"string"`, `"<value>"`, or `0` SHALL NOT be used.

The examples SHALL be declared on the API Platform Resource declarations via the `openApi` extension on `#[QueryParameter]` (using `\ApiPlatform\OpenApi\Model\Parameter` with the `example` field set), so they are co-located with the parameter they document.

This requirement applies to operations on paths matching `^/v1/(logs|traces|metrics|spans)(/.*)?$`. Framework-injected pagination parameters (`page`, `itemsPerPage`) and the OTLP write endpoints are out of scope.

Per-operation request-body and response-body examples (named simple + medium-complex maps under `examples`) are deferred to a follow-up change. The parameter-level examples shipped here cover the dominant DX use case (Swagger UI's "Try it" form auto-fill, generated client fixtures, copy-paste curl recipes).

#### Scenario: Every read query parameter has at least one example
- **WHEN** the OpenAPI document is loaded
- **THEN** for every operation under a read API path, every documented `parameters[*]` entry (excluding framework parameters like `page` / `itemsPerPage`) has `example` set OR a non-empty `examples` map

#### Scenario: Examples use realistic values
- **WHEN** an OpenAPI parameter `traceId` carries an example
- **THEN** the example value is a 32-character lowercase-hex string conforming to the `^[0-9a-f]{32}$` schema

#### Scenario: Swagger UI surfaces the example as the default
- **WHEN** an operator opens `/docs` and selects a read operation's "Try it"
- **THEN** the form's input fields are pre-populated with the example values declared on the parameters

### Requirement: CI lint enforces example coverage on the OpenAPI document

The system SHALL ship a CI lint command that loads the auto-generated OpenAPI document and verifies the example-coverage rule above. The lint SHALL exit non-zero on any violation, printing each offending operation path, HTTP method, and parameter name.

The lint SHALL be invokable as `bin/console app:openapi:lint-examples` and SHALL be runnable as a CI step alongside the unit/functional test runs. A functional test (`OpenApiLintExamplesTest`) verifies the lint passes against the current spec.

The lint SHALL scope to read API operations (paths matching `^/v1/(logs|traces|metrics|spans)(/.*)?$`) and SHALL skip framework-injected parameters by name (`page`, `itemsPerPage`, `pagination`).

#### Scenario: Lint passes on a fully populated spec
- **WHEN** every in-scope read API parameter declares an example
- **AND** the lint command is run
- **THEN** the command exits with status 0

#### Scenario: Lint fails on a missing parameter example
- **WHEN** any in-scope read API parameter lacks both `example` and a non-empty `examples` map
- **AND** the lint command is run
- **THEN** the command exits with non-zero status
- **AND** the output names the offending operation path, HTTP method, and parameter name

#### Scenario: Lint scopes to read API only
- **WHEN** an operation outside the read API path scope (e.g., the OTLP write endpoint at `POST /v1/logs`) lacks examples
- **AND** the lint command is run
- **THEN** the command does NOT report it as a violation

#### Scenario: Lint exempts framework-injected parameters
- **WHEN** a read API operation declares pagination via API Platform (auto-injecting `page` / `itemsPerPage`)
- **AND** those auto-injected parameters lack examples
- **THEN** the lint does NOT report a violation for them

### Requirement: Aggregation endpoints reference

The read API SHALL include aggregation endpoints under `GET /v1/<signal>/aggregate` whose detailed semantics are defined by the `read-aggregations` capability. The aggregation endpoints SHALL share the firewall, Bearer-token authentication, tenant scoping, time-window cap, and execution-timeout governance defined elsewhere in this capability for GET search endpoints.

The OpenAPI 3 specification at `/docs.jsonopenapi` SHALL document the aggregation endpoints alongside the GET search and Trace.Get / Span.Get operations, including their `function`, `column`, `groupBy`, and `interval` parameters with the example coverage required by `read-api`'s "OpenAPI document carries simple and medium-complex examples" requirement.

#### Scenario: Aggregate endpoints documented in OpenAPI
- **WHEN** the OpenAPI document is loaded
- **THEN** the `paths` object contains entries for `/v1/logs/aggregate`, `/v1/traces/aggregate`, `/v1/metrics/aggregate`
- **AND** each carries documented `function`, `column`, `groupBy`, and `interval` parameters

#### Scenario: Aggregate endpoints inherit auth and tenancy
- **WHEN** an aggregation request arrives without a bearer or with a bearer for a different tenant than the data
- **THEN** the response status is 401 (missing/invalid bearer) or 400 (cross-tenant cursor) with the same envelope as the GET search

#### Scenario: Aggregate endpoints inherit timeout governance
- **WHEN** an aggregation request exceeds `crashler.read.execution_timeout_seconds`
- **THEN** the response status is 504 with the same message and envelope as the GET search timeout

### Requirement: Compatibility shims reference

The system SHALL expose a family of compatibility shims under `/compat/<vendor>/` paths whose detailed semantics are defined by the `compat-shims` umbrella capability and its sibling capabilities (`compat-tempo`, `compat-loki`, `compat-prometheus`). The shims SHALL share `read-api`'s Bearer authentication, tenant scoping, time-window cap, and execution-timeout governance.

The shims are NOT part of the canonical `/v1/` read API contract. They exist to make Grafana data sources work without rebuilding dashboards. Shim endpoints SHALL NOT be documented in the `/docs.jsonopenapi` OpenAPI document; their consumer contract is the upstream API documentation of the project they imitate, plus the explicit non-preservations listed in each shim's spec.

#### Scenario: Shims are reachable when enabled
- **WHEN** any `crashler.compat.<vendor>.enabled` flag is true
- **AND** the corresponding shim endpoint is invoked with a valid bearer
- **THEN** the response is shaped per the shim's spec

#### Scenario: Shims absent from /v1/ OpenAPI document
- **WHEN** the OpenAPI document at `/docs.jsonopenapi` is loaded
- **THEN** no path under `/compat/` appears
- **AND** the documented `paths` are exactly the canonical `/v1/` set

#### Scenario: Shim flags do not affect /v1/
- **WHEN** any combination of shim flags is toggled
- **AND** an existing `/v1/` request is sent
- **THEN** the response is identical to the response with the same flags in any other state

