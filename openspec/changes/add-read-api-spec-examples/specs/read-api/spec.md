## ADDED Requirements

### Requirement: OpenAPI document carries simple and medium-complex examples on every read operation

The auto-generated OpenAPI 3.1 document SHALL carry, for every read API operation, at minimum:

- An `example` (or named entry under `examples`) on every documented query parameter.
- A simple-case example AND a medium-complex example on each operation's request body (where the operation accepts a body) and on each operation's primary `200` response body per documented media type.

The "simple" example SHALL exercise the minimum useful set of parameters: typically a time window plus zero or one filter (e.g. `?since=1h&service=checkout`).

The "medium-complex" example SHALL exercise three or more orthogonal capabilities of the endpoint (e.g. service filter + severity filter + attribute filter + non-default `limit`; or a body-search criteria-tree mixing `all` / `any` / a column op and an attribute op; or a Trace.Get with `since`/`until` + `Accept: application/otlp+json`).

Both examples SHALL use realistic values resembling on-disk OTLP shapes (real-shape hex IDs, OTLP-aligned severity numbers, real-shape service names, well-formed timestamps in both RFC3339 and unix-nano forms). Placeholder values such as `"string"`, `"<value>"`, or `0` SHALL NOT be used.

The examples SHALL be declared on the API Platform Resource declarations through the `openapi` / `openapiContext` extension keys on `#[QueryParameter]` and `#[Get]` / `#[GetCollection]` / `#[Post]` operations, so they are co-located with the parameter or operation they document.

This requirement applies to operations on paths matching `^/v1/(logs|traces|metrics|spans)(/.*)?$`. The OTLP write endpoints, although they share these paths on POST, are out of scope for this requirement (they are not declared via API Platform attributes and are governed by the OTLP protocol contract).

#### Scenario: Every read query parameter has at least one example
- **WHEN** the OpenAPI document is loaded
- **THEN** for every operation under a read API path, every `parameters[*]` entry has `example` set OR a non-empty `examples` map

#### Scenario: Every read operation has at least two named examples
- **WHEN** the OpenAPI document is loaded
- **THEN** for every read API operation, the union of named `examples` declared on its parameters, request body (if any), and `200` response body contains at least one `simple` and at least one `complex` (or equivalently named) entry

#### Scenario: Examples use realistic values
- **WHEN** an OpenAPI parameter `traceId` carries an example
- **THEN** the example value is a 32-character lowercase-hex string conforming to the `^[0-9a-f]{32}$` schema

#### Scenario: Swagger UI surfaces the simple example as the default
- **WHEN** an operator opens `/docs` and selects a read operation's "Try it"
- **THEN** the form's input fields are pre-populated with the simple-example values declared on the parameters

### Requirement: CI lint enforces example coverage on the OpenAPI document

The system SHALL ship a CI lint that, on every build, loads the auto-generated OpenAPI document and verifies the example-coverage rule above. The lint SHALL exit non-zero on any violation, printing the offending operation, parameter, response status, and the missing element.

The lint SHALL be invokable as `bin/console app:openapi:lint-examples` (or equivalent) and SHALL run as a step in the existing test pipeline alongside the unit/functional test runs.

The lint SHALL apply only to read API operations (paths matching `^/v1/(logs|traces|metrics|spans)(/.*)?$`); operations outside this scope SHALL be ignored.

#### Scenario: Lint passes on a fully populated spec
- **WHEN** every read API operation declares its examples per the rule above
- **AND** the lint command is run
- **THEN** the command exits with status 0

#### Scenario: Lint fails on a missing parameter example
- **WHEN** any read API parameter lacks both `example` and a non-empty `examples` map
- **AND** the lint command is run
- **THEN** the command exits with non-zero status
- **AND** the output names the offending operation path, HTTP method, and parameter name

#### Scenario: Lint fails on a missing operation-level example
- **WHEN** a read API operation's parameters, request body, and primary 200 response together carry fewer than two named examples
- **AND** the lint command is run
- **THEN** the command exits with non-zero status
- **AND** the output names the operation and indicates which slot (simple or complex) is missing

#### Scenario: Lint scopes to read API only
- **WHEN** an operation outside the read API path scope (e.g., the OTLP write endpoint at `POST /v1/logs`) lacks examples
- **AND** the lint command is run
- **THEN** the command does NOT report it as a violation
