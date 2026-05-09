# compat-tempo Specification

## Purpose
TBD - created by archiving change add-read-compat-shims. Update Purpose after archive.
## Requirements
### Requirement: Tempo shim — pinned to Tempo 2.x

The system SHALL expose a Tempo-compatible shim under `/compat/tempo/api/` when `crashler.compat.tempo.enabled` is `true`. The shim pins to the Tempo 2.x HTTP API.

The v1 surface ships only the connection-test endpoint:

- `GET /compat/tempo/api/echo`

This endpoint is sufficient to satisfy Grafana's Tempo data source "Test connection" health check. Search (`/api/search`) and trace-by-ID (`/api/traces/{traceId}`) endpoints are tracked as separate follow-up requirements.

The endpoint SHALL share `read-api`'s Bearer auth, tenant scoping, and execution timeout. The body returned for `/api/echo` is the literal string `echo`.

#### Scenario: Pinned version is Tempo 2.x
- **WHEN** an operator inspects this shim's specification or its README section
- **THEN** the pinned upstream version is named as Tempo 2.x

#### Scenario: Echo returns 200 with Tempo's expected body shape
- **WHEN** `GET /compat/tempo/api/echo` arrives with a valid bearer and the shim is enabled
- **THEN** the response status is 200
- **AND** the body is the literal string `echo`

#### Scenario: Echo returns 404 when shim is disabled
- **WHEN** `crashler.compat.tempo.enabled` is `false`
- **AND** `GET /compat/tempo/api/echo` arrives with a valid bearer
- **THEN** the response status is 404

#### Scenario: Echo requires bearer token
- **WHEN** `GET /compat/tempo/api/echo` arrives without an `Authorization` header
- **THEN** the response status is 401 (firewall pattern catches `^/(v1|compat)/`)

