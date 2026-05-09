## MODIFIED Requirements

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
