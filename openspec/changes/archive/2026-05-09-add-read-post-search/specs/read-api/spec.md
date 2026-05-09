## ADDED Requirements

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
