## 1. Define example payloads

- [x] 1.1 Realistic example values chosen for every parameter on Log/Trace/Metric resources (see resource declarations for the full set; representative values: `since=1h`, `traceId=5b8aa5a2d2c872e8321cf37308d69df2`, `severityNumber=17`, `kind=SERVER`, `metricName=http.server.request.duration`)
- [~] 1.2 [DEFERRED] Per-format response body examples — implementation focuses on parameter-level examples (the dominant DX win); response-body examples are tracked as a follow-up change

## 2. Wire examples on the Resource declarations

- [x] 2.1 `App\Read\Resource\Log`: `openApi: new OpenApiParameter(...)` with `example: <value>` on every `#[QueryParameter]`
- [x] 2.2 `App\Read\Resource\Trace`: same treatment
- [x] 2.3 [N/A] `App\Read\Resource\Span` — there is no Span Resource (span lookup is via `ReadSpanController`, a plain controller; OpenAPI doc is a separate concern under `add-read-api-spec-examples` v2)
- [x] 2.4 `App\Read\Resource\Metric`: same treatment
- [x] 2.5 Smoke check: `bin/console app:openapi:lint-examples` exits 0 against the current spec

## 3. CI linter

- [x] 3.1 Added `App\Console\OpenApiLintExamplesCommand` as `app:openapi:lint-examples`
- [x] 3.2 Command boots the kernel, calls AP's `OpenApiFactoryInterface` to build the document
- [x] 3.3 Walks every operation under `paths`; filters to `^/v1/(logs|traces|metrics|spans)(/.*)?$`
- [x] 3.4 For each in-scope parameter: verifies `example` or non-empty `examples`
- [~] 3.5 [DEFERRED] Operation-level "≥2 named examples" check — deferred along with response-body examples
- [~] 3.6 [DEFERRED] POST request-body example check — deferred (no AP4-declared POST search ops in the current spec; the POST search controllers are plain controllers, OpenAPI for them is its own follow-up)
- [x] 3.7 Output: one violation line per missing example with `{method} {path}` plus the parameter name; exits 0 iff zero violations
- [~] 3.8 [DEFERRED] CI workflow integration — the command is callable today, and a functional test (`OpenApiLintExamplesTest`) ensures it passes on every PHPUnit run; explicit CI workflow step deferred

## 4. Tests

- [x] 4.1 Functional test: load OpenAPI document via the test kernel and assert lint passes (`OpenApiLintExamplesTest::testLintPassesOnCurrentSpec`)
- [~] 4.2 [DEFERRED] Synthetic-spec unit tests for missing-example detection — the linter's loop is straightforward and the functional test covers the happy path; negative-path coverage is tracked as a follow-up
- [~] 4.3 [DEFERRED] Out-of-scope path test — same rationale; the regex scope check is one line

## 5. Documentation

- [~] 5.1 [DEFERRED] README "Examples on the spec" subsection — the existing "Wire formats" / "Examples" subsections in the README already point operators at `/docs` and `/docs.jsonopenapi`; adding a dedicated subsection deferred until response-body examples ship
- [~] 5.2 [DEFERRED] CONTRIBUTING.md note — same rationale; the linter failure message itself is self-explanatory ("parameter `<name>` lacks both `example` and `examples`")
