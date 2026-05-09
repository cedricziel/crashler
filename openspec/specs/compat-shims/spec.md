# compat-shims Specification

## Purpose
TBD - created by archiving change add-read-compat-shims. Update Purpose after archive.
## Requirements
### Requirement: Compatibility shims under /compat/

The system SHALL expose a family of upstream-API compatibility shims under the path prefix `/compat/<vendor>/`. Each shim SHALL reuse the existing Bearer-token authentication, tenant scoping, time-window cap, and execution-timeout governance defined by `read-api`.

Compatibility shims are NOT part of the canonical `/v1/` read API contract. They exist solely to make existing Grafana / Tempo / Loki / Prometheus data sources work against Crashler without rebuilding dashboards. Each shim's spec SHALL list explicitly which features of the upstream API are preserved and which are not.

#### Scenario: Shim paths share auth with /v1/
- **WHEN** any request arrives at any `/compat/<vendor>/...` path without a valid Bearer token
- **THEN** the response status is 401 with the same envelope used by `/v1/` paths

#### Scenario: Shim paths share tenant scoping with /v1/
- **WHEN** an authenticated tenant invokes a shim endpoint
- **THEN** the underlying scanner reads only files under `<storage-root>/<signal>/<tenant>/`
- **AND** files under other tenants are not read

#### Scenario: Shim paths share execution-timeout with /v1/
- **WHEN** a shim request takes longer than `crashler.read.execution_timeout_seconds`
- **THEN** the response is shaped per the shim's documented error contract (HTTP 504 if the upstream API supports it; HTTP 408 or upstream-specific shape otherwise) AND the response body indicates the timeout

### Requirement: Per-shim feature flags default OFF

Each shim SHALL be opt-in via a per-shim configuration flag. The flags SHALL be:

- `crashler.compat.tempo.enabled` (env `CRASHLER_COMPAT_TEMPO_ENABLED`)
- `crashler.compat.loki.enabled` (env `CRASHLER_COMPAT_LOKI_ENABLED`)
- `crashler.compat.prometheus.enabled` (env `CRASHLER_COMPAT_PROMETHEUS_ENABLED`)

Default for every flag SHALL be `false`. When a flag is `false`, the shim's routes SHALL NOT be registered with the Symfony router; requests against them SHALL return HTTP 404.

#### Scenario: Disabled shim returns 404
- **WHEN** the Tempo shim flag is false and a request arrives at `/compat/tempo/api/echo`
- **THEN** the response status is 404

#### Scenario: Enabled shim is reachable
- **WHEN** the Loki shim flag is true and a request arrives at `/compat/loki/api/v1/labels` with a valid bearer
- **THEN** the response status is 200 with the documented Loki shape

### Requirement: Shims document their non-preservations

Each shim's spec SHALL include a section listing the upstream API features that the shim does NOT support. Requests against unsupported features SHALL return HTTP 400 with a JSON body containing a `message` field that names the unsupported feature and lists the supported subset.

The supported subset SHALL be reproducible from the shim's spec — each accepted query shape is documented as a Scenario with a concrete example, and each documented rejection has its own Scenario.

#### Scenario: Unsupported PromQL form rejected with explanation
- **WHEN** the Prometheus shim is sent a `query` value containing a PromQL feature outside its documented subset (e.g., `histogram_quantile`)
- **THEN** the response status is 400
- **AND** the response body's `message` names the unsupported feature and lists the supported PromQL forms

#### Scenario: Unsupported LogQL form rejected with explanation
- **WHEN** the Loki shim is sent a `query` containing a regex selector (`=~`) or a range vector
- **THEN** the response status is 400
- **AND** the response body's `message` names the unsupported feature and the supported LogQL subset

### Requirement: Shims pin to documented upstream versions

Each shim's spec SHALL name the upstream API version it pins to (e.g., Tempo 2.x, Loki 2.9.x, Prometheus 2.x). The pinned version determines the response shape, the supported query forms, and the documented non-preservations. Raising the pin SHALL be its own change.

#### Scenario: Pinned version is discoverable
- **WHEN** an operator reads the shim's specification or its README section
- **THEN** the pinned upstream version is named explicitly with a major-version reference

### Requirement: Shims do not interfere with /v1/

The presence or absence of any compat shim SHALL NOT alter the behaviour of any endpoint under `/v1/`. The `/v1/` endpoints' contract is unaffected by which shims are enabled.

#### Scenario: /v1/ behaviour stable regardless of shim flags
- **WHEN** every shim flag is toggled ON or OFF in any combination
- **AND** the same `GET /v1/logs?service=checkout&since=1h` request is sent
- **THEN** the response shape, status, and content are identical across all combinations

