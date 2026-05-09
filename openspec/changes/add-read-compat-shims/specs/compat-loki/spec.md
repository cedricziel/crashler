## ADDED Requirements

### Requirement: Loki shim — pinned to Loki 2.9.x

The system SHALL expose a Loki-compatible shim under `/compat/loki/api/v1/` when `crashler.compat.loki.enabled` is `true`. The shim pins to Loki 2.9.x.

The v1 surface ships only the labels enumeration endpoint:

- `GET /compat/loki/api/v1/labels`

This endpoint is sufficient to satisfy Grafana's Loki data source "Test connection" probe and to populate the label browser dropdown. Label-values enumeration (`/label/{name}/values`) and `query_range` are tracked as separate follow-up requirements.

The endpoint SHALL return `application/json` and SHALL share `read-api`'s Bearer auth, tenant scoping, and execution timeout.

#### Scenario: Pinned version is Loki 2.9.x
- **WHEN** an operator inspects this shim's specification or its README section
- **THEN** the pinned upstream version is named as Loki 2.9.x

#### Scenario: Labels endpoint returns the closed list
- **WHEN** `GET /compat/loki/api/v1/labels` arrives with a valid bearer and the shim is enabled
- **THEN** the response status is 200
- **AND** the `Content-Type` is `application/json`
- **AND** the body's `status` is `success`
- **AND** the body's `data` is a JSON array containing exactly `service`, `environment`, `host`, `severityText`, `severityNumber`

#### Scenario: Labels returns 404 when shim is disabled
- **WHEN** `crashler.compat.loki.enabled` is `false`
- **AND** `GET /compat/loki/api/v1/labels` arrives with a valid bearer
- **THEN** the response status is 404

#### Scenario: Labels requires bearer token
- **WHEN** `GET /compat/loki/api/v1/labels` arrives without an `Authorization` header
- **THEN** the response status is 401
