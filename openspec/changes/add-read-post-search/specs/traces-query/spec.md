## ADDED Requirements

### Requirement: POST /v1/traces/search

The system SHALL expose `POST /v1/traces/search` via either a `#[Post]` operation on the `App\Read\Resource\Trace` Resource OR a plain Symfony controller with `#[Route('/v1/traces/search', methods: ['POST'])]` (e.g., `App\Read\Controller\PostTracesSearchController`). The operation SHALL share the firewall, Bearer-token authentication, and tenant scoping of the existing `GET /v1/traces` collection.

The processor SHALL accept a JSON body matching the predicate-tree DSL defined in `read-api`. The allowed columns SHALL match the GET filter set on traces:

- `resource_service_name`, `resource_deployment_environment`, `resource_host_name`
- `name` (compiles to `ColumnEquals` for `eq`, `ColumnLikePrefix` / `ColumnLikeSuffix` for `prefix` / `suffix` ops)
- `kind_text`, `status_text`
- `http_response_status_code`
- `trace_id_hex`, `parent_span_id_hex`
- `time_unix_nano`

The `body` leaf SHALL be rejected (logs-only). The `attribute` leaf SHALL compile to `JsonAttributeEquals('attributes_json', key, value)`.

POST search on traces returns the flat collection shape — i.e., one row per matching span — as the GET search does. POST search SHALL NOT return the OTLP `ResourceSpans` tree shape; that shape is reserved for the by-ID `GET /v1/traces/{traceId}` operation.

#### Scenario: POST search returns the flat span collection
- **WHEN** `POST /v1/traces/search` is invoked with a valid criteria
- **THEN** the response shape matches `GET /v1/traces?...` — one row per span, not OTLP-grouped

#### Scenario: POST search supports kind OR
- **WHEN** `POST /v1/traces/search` is invoked with `criteria = {"any": [{"column": "kind_text", "op": "eq", "value": "SERVER"}, {"column": "kind_text", "op": "eq", "value": "CLIENT"}]}`
- **THEN** the response contains spans whose `kind` is either SERVER or CLIENT

#### Scenario: POST search supports name with prefix and NOT
- **WHEN** `POST /v1/traces/search` is invoked with `criteria = {"all": [{"column": "name", "op": "prefix", "value": "GET /orders/"}, {"not": {"column": "status_text", "op": "eq", "value": "OK"}}]}`
- **THEN** the response contains spans whose `name` starts with `GET /orders/` AND whose `statusCode` is not `OK`

#### Scenario: POST search rejects body filter on traces
- **WHEN** `POST /v1/traces/search` is invoked with a criteria containing a `{"body": "contains", "value": "..."}` leaf
- **THEN** the system responds with HTTP 400 with a message that body filters are logs-only
