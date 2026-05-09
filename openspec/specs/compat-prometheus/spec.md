# compat-prometheus Specification

## Purpose
TBD - created by archiving change add-read-compat-shims. Update Purpose after archive.
## Requirements
### Requirement: Prometheus shim — pinned to Prometheus 2.x

The system SHALL expose a Prometheus-compatible shim under `/compat/prom/api/v1/` when `crashler.compat.prometheus.enabled` is `true`. The shim pins to the Prometheus 2.x HTTP API.

The v1 surface ships only the labels enumeration endpoint:

- `GET /compat/prom/api/v1/labels`

This endpoint is sufficient to satisfy Grafana's Prometheus data source "Test connection" probe and to populate the label browser dropdown. Label-values enumeration (`/label/{name}/values`) and `query_range` (with the documented PromQL subset) are tracked as separate follow-up requirements; the latter depends on the aggregation primitives shipped under `add-read-aggregations`.

The endpoint SHALL return `application/json` and SHALL share `read-api`'s Bearer auth, tenant scoping, and execution timeout.

#### Scenario: Pinned version is Prometheus 2.x
- **WHEN** an operator inspects this shim's specification or its README section
- **THEN** the pinned upstream version is named as Prometheus 2.x

#### Scenario: Labels endpoint returns the closed list
- **WHEN** `GET /compat/prom/api/v1/labels` arrives with a valid bearer and the shim is enabled
- **THEN** the response status is 200
- **AND** the `Content-Type` is `application/json`
- **AND** the body's `status` is `success`
- **AND** the body's `data` array contains the labels exposed by the metrics signal: `service`, `environment`, `host`, `metricName`, `metricType`, `aggregationTemporality`

#### Scenario: Labels returns 404 when shim is disabled
- **WHEN** `crashler.compat.prometheus.enabled` is `false`
- **AND** `GET /compat/prom/api/v1/labels` arrives with a valid bearer
- **THEN** the response status is 404

#### Scenario: Labels requires bearer token
- **WHEN** `GET /compat/prom/api/v1/labels` arrives without an `Authorization` header
- **THEN** the response status is 401

