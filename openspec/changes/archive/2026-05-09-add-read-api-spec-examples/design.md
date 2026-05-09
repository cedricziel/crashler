## Context

The OpenAPI document at `/docs.jsonopenapi` and the Swagger UI at `/docs` together form the canonical consumer contract for the read API. Today they document *which* parameters exist and *what types* they take, but not *what real values look like*. The gap matters most for:

- The Swagger UI's "Try it" form — currently presents an empty input box for `since`, when a one-character example like `1h` would teach the user the duration shorthand grammar in zero seconds.
- Generated clients — codegen tools (openapi-generator, openapi-typescript, etc.) emit fixture stubs from the `example` keyword. Without examples, fixtures are `null` or the schema's default.
- AI assistants and copy-paste workflows — operators routinely ask "give me a curl that hits this endpoint with sensible filters". An OpenAPI doc with examples answers that for free; without, the assistant is reduced to inferring from prose.

Stakeholders:
- Operators integrating tooling against the API
- Internal teams writing dashboards
- Future changes (`add-read-post-search`, `add-read-aggregations`, etc.) which inherit this requirement on their new endpoints

The OpenAPI 3.1 spec offers two ways to attach examples:
- `example` — a single example value
- `examples` — a named map (`{simple: {value: ...}, complex: {value: ...}}`)

We use both: `example` for the simple case (which Swagger UI auto-fills into "Try it"), and `examples` for the named medium-complex case (rendered in the "Examples" dropdown).

API Platform 4 surfaces this through the `openapi` extension on `#[QueryParameter]` and `#[ApiResource]`/`#[Get]`/`#[Post]` attributes. AP carries the values verbatim into the generated OpenAPI document.

## Goals / Non-Goals

**Goals:**
- Every parameter on every read operation has at least one `example` in the OpenAPI document.
- Every operation has at least one named example for the request body (POST search) or response body (GET searches and Trace.Get).
- Every operation has both a "simple" and a "medium-complex" example accessible to consumers.
- A CI linter enforces the rule going forward, so future Resource additions do not silently regress documentation quality.
- Examples are realistic — values that resemble the on-disk shape of real OTLP data, not `"string"` placeholders.

**Non-Goals:**
- Multi-language code samples (Swagger's `x-codeSamples`). Out of scope; can be layered on top.
- Localisation of example descriptions. Out of scope.
- Dynamic examples that change with each documentation render. Examples are static literals declared on the PHP attributes.
- Documentation of error response bodies beyond the schema-level `message` field already documented.
- Backfilling examples on the OTLP *write* endpoints. They are not declared via AP4 attributes; bringing them under the same regime is its own change.

## Decisions

### Decision 1: "Simple" + "medium-complex" — concrete definitions
**Choice:** the rule operationalises as:
- A "simple" example exercises the minimum useful set of parameters: the time window plus zero or one other filter. For most endpoints this is `?since=1h` or `?since=1h&service=checkout`.
- A "medium-complex" example exercises three or more orthogonal capabilities of the endpoint. For `GET /v1/logs` it might be: a service filter + a severity-min filter + an attribute filter + a non-default `limit`. For Trace.Get it might be: a specific traceId + an `Accept: application/otlp+json` header + a `since`/`until` window.

Both are declared as named entries in the `examples` map on the parameter or operation.

**Why:** "two examples" is enforceable; "ample examples" is not. Naming the simple-vs-complex distinction by exact criteria (zero-or-one extra filter / three+ orthogonal capabilities) makes the linter mechanically checkable and gives reviewers a yardstick for new endpoints.

### Decision 2: Examples live on the Resource declarations, not in a separate fixtures file
**Choice:** examples are declared inline on the `#[QueryParameter]`'s `openapi` array and on the operation's `openapiContext` array, alongside the property they document. AP4 picks them up through the standard extension path.

**Alternative considered:** a separate `examples.yaml` keyed by operation+parameter, loaded into AP via a custom processor. Rejected — splits the single source of truth from the Resource where it belongs; harder for future contributors to keep in sync.

**Why:** colocation is the defensive position. A reviewer touching the parameter sees the example next to it and can update both at once.

### Decision 3: One CI linter, reading the rendered document
**Choice:** a small PHP file (`tools/openapi-examples-lint.php`) or Symfony console command that:
1. Boots the kernel
2. Calls AP's OpenAPI factory to render the document (no HTTP roundtrip needed)
3. Walks every operation under `paths`
4. For each operation: every `parameters[*]` MUST have `example` or `examples`; the response's `200` content MUST have `example` or `examples` per media type; for POST operations the `requestBody.content.<media>` MUST have `example` or `examples`
5. Asserts at least two named examples per operation overall (simple + medium-complex)
6. Exits non-zero on any violation, printing the offending operation/parameter

The linter runs as a CI step alongside the existing PHPUnit run.

**Alternative considered:** a Spectral ruleset (`@stoplight/spectral`). Rejected — adds a Node toolchain to a PHP-only repo; the rule is simple enough that an in-tree linter is cheaper than the dependency.

**Why:** lint rules that match the spec's intent better than off-the-shelf rulesets, no new toolchain.

### Decision 4: Lint applies to read-API operations only
**Choice:** the linter scopes to operations whose path matches `^/v1/(logs|traces|metrics|spans)(/|$)`. The OTLP write endpoints (also under `/v1/logs`, `/v1/traces`, `/v1/metrics` but on POST with the OTLP shape) are excluded by an explicit allow-list of methods+paths.

**Why:** the OTLP write side is a protocol contract, not a read-API DX surface. Bringing it under this requirement is a separate concern; failing CI on its absence today would be incorrect scope.

### Decision 5: Realistic example values
**Choice:** examples use values that look like real OTLP data:
- `traceId`: a real-shape 32-hex-char string (e.g. `5b8aa5a2d2c872e8321cf37308d69df2`)
- `service`: real service-name shapes (`checkout`, `payments`, `billing-api`)
- `severityNumber`: OTLP-aligned values (`9`, `13`, `17`, `21`)
- `since`: both shorthand (`1h`) and RFC3339 (`2026-05-09T13:00:00Z`) forms across different parameters so consumers see both
- Response examples for the collection endpoints carry 1–2 row members showing the real column shape

**Why:** value realism is the difference between "Try it works" and "Try it teaches the user the API". The marginal cost of one realistic value vs. one placeholder is zero; the documentation payoff is large.

## Risks / Trade-offs

- **Risk: examples drift from reality as the on-disk schema evolves.** → Mitigation: the example values are written close to the parameter declarations, so a parameter rename or a column addition is structurally tied to its example. The linter doesn't validate that examples are *current*, only that they exist; we accept that drift is reviewer-caught, not lint-caught.

- **Risk: lint blocks unrelated PRs.** A PR adding a new operation must also add examples. → Mitigation: this is intentional. The lint failure points the contributor at the missing examples; the change is small (a few lines).

- **Risk: AP4 ignores or mangles the `openapi` attribute on `#[QueryParameter]`.** → Mitigation: a smoke test in the linter rendering pipeline asserts that for at least one well-known parameter (`since`), the example value survives end-to-end into the rendered document.

- **Trade-off: PHP attribute syntax for examples is verbose.** Five examples per parameter × ~12 parameters per Resource = a lot of attribute boilerplate. → Mitigation: the requirement says "at least two named examples per operation, plus at least one example per parameter". That is two per parameter and two per operation, not five each. The volume is manageable.

- **Trade-off: lint rule is hand-written.** It is one PHP file, but it is one PHP file we have to maintain. → Mitigation: the linter is small (~150 lines including walks and error formatting) and deeply scoped. We accept the maintenance.

## Migration Plan

- No data migration. No on-disk change.
- Roll-forward: add example data to the existing Resource declarations; add the linter; wire it into CI. The linter's first run will (intentionally) fail on the existing Resources until they are populated. We populate them in the same change.
- Rollback: revert. Examples disappear from the OpenAPI document; no consumer breaks because example absence is a soft DX regression, not a contract change.
- Communication: README's "Reading data" section gains a sentence pointing operators at the Swagger UI's example payloads; CHANGELOG entry notes the OpenAPI improvement.

## Open Questions

- Should we publish a separate "API examples gallery" page (a Twig template that renders the examples directly from the OpenAPI source)? Out of scope for this change but a low-effort follow-up if Swagger's "Examples" dropdown is felt to be insufficient.
- Should the linter also assert that examples *parse* against their declared schema? Tempting, but pulls in an OpenAPI schema validator. Defer until proven needed.
