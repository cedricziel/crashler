## ADDED Requirements

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
