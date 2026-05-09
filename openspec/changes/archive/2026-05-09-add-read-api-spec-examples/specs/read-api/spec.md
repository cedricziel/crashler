## ADDED Requirements

### Requirement: OpenAPI document carries examples on every read operation parameter

The auto-generated OpenAPI 3.1 document SHALL carry, for every read API operation, an `example` (or non-empty `examples` map) on every documented query parameter. The example value SHALL be a realistic value resembling on-disk OTLP shapes (real-shape hex IDs, OTLP-aligned severity numbers, real service names, RFC3339 or unix-nano timestamps). Placeholder values such as `"string"`, `"<value>"`, or `0` SHALL NOT be used.

The examples SHALL be declared on the API Platform Resource declarations via the `openApi` extension on `#[QueryParameter]` (using `\ApiPlatform\OpenApi\Model\Parameter` with the `example` field set), so they are co-located with the parameter they document.

This requirement applies to operations on paths matching `^/v1/(logs|traces|metrics|spans)(/.*)?$`. Framework-injected pagination parameters (`page`, `itemsPerPage`) and the OTLP write endpoints are out of scope.

Per-operation request-body and response-body examples (named simple + medium-complex maps under `examples`) are deferred to a follow-up change. The parameter-level examples shipped here cover the dominant DX use case (Swagger UI's "Try it" form auto-fill, generated client fixtures, copy-paste curl recipes).

#### Scenario: Every read query parameter has at least one example
- **WHEN** the OpenAPI document is loaded
- **THEN** for every operation under a read API path, every documented `parameters[*]` entry (excluding framework parameters like `page` / `itemsPerPage`) has `example` set OR a non-empty `examples` map

#### Scenario: Examples use realistic values
- **WHEN** an OpenAPI parameter `traceId` carries an example
- **THEN** the example value is a 32-character lowercase-hex string conforming to the `^[0-9a-f]{32}$` schema

#### Scenario: Swagger UI surfaces the example as the default
- **WHEN** an operator opens `/docs` and selects a read operation's "Try it"
- **THEN** the form's input fields are pre-populated with the example values declared on the parameters

### Requirement: CI lint enforces example coverage on the OpenAPI document

The system SHALL ship a CI lint command that loads the auto-generated OpenAPI document and verifies the example-coverage rule above. The lint SHALL exit non-zero on any violation, printing each offending operation path, HTTP method, and parameter name.

The lint SHALL be invokable as `bin/console app:openapi:lint-examples` and SHALL be runnable as a CI step alongside the unit/functional test runs. A functional test (`OpenApiLintExamplesTest`) verifies the lint passes against the current spec.

The lint SHALL scope to read API operations (paths matching `^/v1/(logs|traces|metrics|spans)(/.*)?$`) and SHALL skip framework-injected parameters by name (`page`, `itemsPerPage`, `pagination`).

#### Scenario: Lint passes on a fully populated spec
- **WHEN** every in-scope read API parameter declares an example
- **AND** the lint command is run
- **THEN** the command exits with status 0

#### Scenario: Lint fails on a missing parameter example
- **WHEN** any in-scope read API parameter lacks both `example` and a non-empty `examples` map
- **AND** the lint command is run
- **THEN** the command exits with non-zero status
- **AND** the output names the offending operation path, HTTP method, and parameter name

#### Scenario: Lint scopes to read API only
- **WHEN** an operation outside the read API path scope (e.g., the OTLP write endpoint at `POST /v1/logs`) lacks examples
- **AND** the lint command is run
- **THEN** the command does NOT report it as a violation

#### Scenario: Lint exempts framework-injected parameters
- **WHEN** a read API operation declares pagination via API Platform (auto-injecting `page` / `itemsPerPage`)
- **AND** those auto-injected parameters lack examples
- **THEN** the lint does NOT report a violation for them
