## ADDED Requirements

### Requirement: POST /v1/metrics/search

The system SHALL expose `POST /v1/metrics/search` via either a `#[Post]` operation on the `App\Read\Resource\Metric` Resource OR a plain Symfony controller with `#[Route('/v1/metrics/search', methods: ['POST'])]` (e.g., `App\Read\Controller\PostMetricsSearchController`). The operation SHALL share the firewall, Bearer-token authentication, and tenant scoping of the existing `GET /v1/metrics` collection.

The processor SHALL accept a JSON body matching the predicate-tree DSL defined in `read-api`. The allowed columns SHALL match the GET filter set on metrics:

- `resource_service_name`, `resource_deployment_environment`, `resource_host_name`
- `metric_name`, `metric_type`, `aggregation_temporality_text`
- `time_unix_nano`

The `body` leaf SHALL be rejected (logs-only). The `attribute` leaf SHALL compile to `JsonAttributeEquals('attributes_json', key, value)`. A second leaf form `{"exemplarTraceId": <hex>}` SHALL be accepted as syntactic sugar for `{"attribute": "traceId", "op": "eq", "value": <hex>}` against `exemplars_json`, mirroring the GET endpoint's `exemplarTraceId` parameter.

#### Scenario: POST search supports metric type OR
- **WHEN** `POST /v1/metrics/search` is invoked with `criteria = {"any": [{"column": "metric_type", "op": "eq", "value": "SUM"}, {"column": "metric_type", "op": "eq", "value": "GAUGE"}]}`
- **THEN** the response contains rows whose `metricType` is SUM or GAUGE

#### Scenario: POST search supports name IN-list
- **WHEN** `POST /v1/metrics/search` is invoked with `criteria = {"column": "metric_name", "op": "in", "value": ["http.server.request.duration", "http.client.request.duration"]}`
- **THEN** the response contains rows whose `metricName` is one of the two listed names

#### Scenario: POST search rejects body filter on metrics
- **WHEN** `POST /v1/metrics/search` is invoked with a criteria containing a `{"body": "contains", "value": "..."}` leaf
- **THEN** the system responds with HTTP 400 with a message that body filters are logs-only
