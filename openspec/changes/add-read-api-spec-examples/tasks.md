## 1. Define example payloads

- [ ] 1.1 Decide simple-and-complex example payloads per endpoint, written down in a short note attached to the change directory (does not need to be a normative doc — just the values the developer will paste into PHP attributes)
  - `GET /v1/logs`: simple `since=1h`; complex `since=1h&service=checkout&severityNumberMin=17&attribute.exception.type=RuntimeException&limit=50`
  - `GET /v1/traces`: simple `since=1h`; complex `since=1h&service=checkout&kind=SERVER&httpStatusCodeMin=500&name=GET+/orders/*`
  - `GET /v1/traces/{traceId}`: simple `5b8aa5a2d2c872e8321cf37308d69df2`; complex same id with `since=2h&until=2026-05-09T15:00:00Z&Accept=application/otlp+json`
  - `GET /v1/spans/{spanId}`: simple `051581bf3cb55c13`; complex same id with `since=1h`
  - `GET /v1/metrics`: simple `since=1h`; complex `since=1h&service=checkout&metricType=SUM&metricName=http.server.request.duration&aggregationTemporality=DELTA`
- [ ] 1.2 Decide simple-and-complex response examples for the collection endpoints (one row for simple; two rows including a row with cross-signal `_links` for complex), in jsonld and compact json formats

## 2. Wire examples on the Resource declarations

- [ ] 2.1 `App\Read\Resource\Log`: add `openapi: ['example' => ..., 'examples' => [...]]` (or per-Resource convention) on every `#[QueryParameter]`; add an operation-level `openapiContext` block carrying `examples` for the request and 200-response per media type
- [ ] 2.2 Same for `App\Read\Resource\Trace`
- [ ] 2.3 Same for `App\Read\Resource\Span` (item operation)
- [ ] 2.4 Same for `App\Read\Resource\Metric`
- [ ] 2.5 Smoke check: render `/docs.jsonopenapi`, manually verify the simple example for `since` on `GET /v1/logs` survives end-to-end into the document

## 3. CI linter

- [ ] 3.1 Add Symfony console command `App\Console\OpenApiLintExamplesCommand` registered as `app:openapi:lint-examples`
- [ ] 3.2 Command boots the kernel, calls AP's OpenAPI factory (`ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface`) to build the document
- [ ] 3.3 Walk every operation under `paths`; filter to `^/v1/(logs|traces|metrics|spans)(/.*)?$`
- [ ] 3.4 For each in-scope operation: verify every parameter has `example` or a non-empty `examples` map
- [ ] 3.5 For each in-scope operation: count named examples across parameters, requestBody, and 200 response; fail if fewer than 2 named entries
- [ ] 3.6 For each in-scope POST operation: requestBody.content[application/json] must have `example` or `examples`
- [ ] 3.7 Output: human-readable per-violation lines (`{method} {path} parameter '{name}' has no example`) and an exit code that is 0 iff zero violations
- [ ] 3.8 Add the linter step to the CI workflow (`.github/workflows/*.yml` or equivalent)

## 4. Tests

- [ ] 4.1 Functional test: load the OpenAPI document via the test kernel, walk it, assert at least one example on every documented read parameter (mirrors the linter, runs in PHPUnit so failures show up locally too)
- [ ] 4.2 Unit test: feed the linter a synthetic OpenAPI document with a missing-example parameter; assert the linter emits a violation pointing at the parameter
- [ ] 4.3 Unit test: feed the linter a synthetic OpenAPI document with examples on a non-read path (`POST /v1/logs` write); assert the linter does NOT report a violation

## 5. Documentation

- [ ] 5.1 Add a one-paragraph "Examples on the spec" subsection to the project README's "Reading data" section, pointing operators at `/docs` Swagger UI and the `examples` dropdown
- [ ] 5.2 Add a short note to the contributor guide (or CONTRIBUTING.md if present) explaining the rule for new endpoints: every parameter, every operation, two named examples
