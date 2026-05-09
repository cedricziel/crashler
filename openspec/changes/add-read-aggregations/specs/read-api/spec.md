## ADDED Requirements

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
