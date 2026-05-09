## ADDED Requirements

### Requirement: POST /v1/logs/search

The system SHALL expose `POST /v1/logs/search` via either a `#[Post]` operation on the `App\Read\Resource\Log` Resource OR a plain Symfony controller with `#[Route('/v1/logs/search', methods: ['POST'])]` (e.g., `App\Read\Controller\PostLogsSearchController`). The operation SHALL be subject to the same firewall, Bearer-token authentication, and tenant scoping as the existing `GET /v1/logs` collection.

The processor SHALL accept a JSON body matching the predicate-tree DSL defined in `read-api`. It SHALL allow exactly the column names that are accepted by the existing GET filter set on logs (the camelCase API names map to their snake_case Parquet columns):

- `resource_service_name`, `resource_deployment_environment`, `resource_host_name`
- `severity_number`, `severity_text`
- `trace_id_hex`, `span_id_hex`
- `event_name`
- `time_unix_nano`

A `body` leaf SHALL be supported, compiling to `JsonStringContains('body_json', value)`. An `attribute` leaf SHALL compile to `JsonAttributeEquals('attributes_json', key, value)`. Multiple distinct `attribute` leaves SHALL compose under `Any` / `all` / `not` per the tree's structure, up to the per-request cap from `read-api`.

#### Scenario: POST search returns the same rows as the equivalent GET
- **WHEN** `POST /v1/logs/search` is invoked with `criteria = {"all": [{"column": "resource_service_name", "op": "eq", "value": "checkout"}, {"column": "severity_number", "op": "gte", "value": 17}]}` and matching `since`/`until`
- **AND** an equivalent `GET /v1/logs?service=checkout&severityNumberMin=17&since=...&until=...` is invoked
- **THEN** both responses return the same rows in the same order

#### Scenario: POST search supports OR over service
- **WHEN** `POST /v1/logs/search` is invoked with `criteria = {"any": [{"column": "resource_service_name", "op": "eq", "value": "checkout"}, {"column": "resource_service_name", "op": "eq", "value": "payments"}]}`
- **THEN** the response contains rows whose `resourceServiceName` is `checkout` OR `payments`
- **AND** no rows whose service is anything else

#### Scenario: POST search supports IN over trace IDs
- **WHEN** `POST /v1/logs/search` is invoked with `criteria = {"column": "trace_id_hex", "op": "in", "value": ["<id1>", "<id2>", "<id3>"]}`
- **THEN** the response contains rows whose `traceIdHex` matches one of the three IDs

#### Scenario: POST search supports body substring with NOT
- **WHEN** `POST /v1/logs/search` is invoked with `criteria = {"all": [{"body": "contains", "value": "panic"}, {"not": {"column": "resource_service_name", "op": "eq", "value": "internal"}}]}`
- **THEN** the response contains rows whose `bodyJson` contains the substring `panic` AND whose service is not `internal`
