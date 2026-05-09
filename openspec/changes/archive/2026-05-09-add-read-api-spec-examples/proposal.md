## Why

The read API ships an auto-generated OpenAPI 3.1 document and a Swagger UI, but the OpenAPI parameter and schema entries are bare descriptions — no example values. Operators integrating against the API today see a `since` parameter "Lower bound of the time window" and have to read the prose to know whether `1h`, `2026-05-09T14:00:00Z`, or `1714824000000000000` are all accepted.

This is a developer-experience hole. The OpenAPI spec is the canonical consumer contract; the spec text already says so. Without examples, the contract is half a contract. Tools that consume OpenAPI (Swagger UI's "Try it", Postman, code-generators, AI assistants) all degrade gracefully but markedly when examples are missing — the "Try it" form starts blank, generated client code lacks fixture-quality test data, and search-style copy-paste workflows force users to consult side documentation.

This change formalises a global requirement: every read API operation, parameter, request body, and response body MUST carry both a "simple" example and a "medium-complex" example in the OpenAPI document. This applies to every existing endpoint plus every endpoint added by future changes.

## What Changes

- New normative requirement in `read-api`: every operation MUST include OpenAPI `example` (or `examples`) entries on each query parameter, on the request body schema (where applicable), and on each response status's body schema. At minimum:
  - One **simple** example: the most common single-purpose call. For `GET /v1/logs` that's `?since=1h`.
  - One **medium-complex** example: a call exercising multiple filters, time-window, and (where applicable) pagination cursor. For `GET /v1/logs` that might be `?since=1h&service=checkout&severityNumberMin=17&attribute.exception.type=RuntimeException&limit=50`.
- Examples MUST be wired into the API Platform Resource declarations via the AP4 `openapi` / `openapiContext` parameter on `#[QueryParameter]`, `#[ApiResource]`, and per-operation attributes. AP4 carries them into the generated OpenAPI document under `parameters[*].example` / `examples`, `requestBody.content.<media>.example` / `examples`, and `responses[*].content.<media>.example` / `examples`.
- The Resource classes for `Log`, `Trace`, `Span`, `Metric` SHALL be updated to declare these examples on every parameter and on the response shape per format (jsonld + json minimum; HAL + jsonapi optional but encouraged).
- The OpenAPI CI check SHALL fail when any operation lacks the required examples, computed via a small linter that walks the generated `/docs.jsonopenapi` and asserts the rule holds.
- The project README SHALL include a one-paragraph "Examples on the spec" subsection pointing operators at the `/docs` Swagger UI as the canonical example source, and pointing developers at the linter contract.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `read-api`: adds the global "every operation carries simple + medium-complex examples" requirement and the corresponding CI check requirement. This is a documentation-and-conformance change that does not alter request/response semantics.

## Impact

- New code:
  - Per-Resource: `openapiContext` / `openapi` blocks on every parameter and on the operation declarations supplying the example payloads. ~150 lines net across the four Resource classes.
  - One small CI linter `tools/openapi-examples-lint.php` (or a Symfony console command `app:openapi:lint-examples`) that loads `/docs.jsonopenapi` and walks the operations.
  - One CI step that runs the linter as part of the existing test pipeline.
- Tests: a functional test that loads the OpenAPI document and asserts at least one example is present on every operation/parameter/response per the rule.
- No backward-incompatible changes. The on-the-wire behaviour of every endpoint is unchanged. Only the OpenAPI document grows.
- No new dependencies. AP4 already supports the `openapi` extension keys.
- Operational risk: documentation lint failures can block CI. → Mitigation: the linter's rules are scoped to the read-API operations; pre-existing OTLP write endpoints (which do not declare AP4 parameters) are out of scope for the lint.
