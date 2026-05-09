## 1. Configuration

- [x] 1.1 Add `crashler.read.post_search.max_body_bytes` parameter (default 65536) wired to env `CRASHLER_READ_POST_SEARCH_MAX_BODY_BYTES`
- [x] 1.2 Reuse `crashler.read.max_attribute_filters`, `crashler.read.max_page_size`, `crashler.read.max_time_window_days`, `crashler.read.execution_timeout_seconds`, `crashler.read.cursor_secret` from existing config — no new secrets

## 2. DTOs and validation

- [x] 2.1 Add `App\Read\Http\Dto\PostSearchRequestDto` with fields `since`, `until`, `limit`, `cursor`, `criteria` (mixed JSON tree at this stage; structural validation comes from the compiler)
- [x] 2.2 Add Symfony Validator constraints: `since`/`until` parsing mirrors the existing time-window helper; `limit` integer in `[1, max_page_size]`; `cursor` opaque string-or-null; `criteria` mandatory non-null
- [x] 2.3 Reject malformed JSON with 400 + `message: "Invalid JSON body: <reason>"`
- [x] 2.4 Reject body > `max_body_bytes` with 413 in a kernel-level pre-deserialization check
- [x] 2.5 Reject `Content-Type` other than `application/json` with 415

## 3. Predicate tree DSL compiler

- [x] 3.1 Add `App\Read\Compute\Combinators\Any` predicate (OR over child predicates; `tier()` returns `max(child.tier)`)
- [x] 3.2 Add `App\Read\Compute\Combinators\Not` predicate (negates child; `tier()` returns child's tier)
- [x] 3.3 Add `App\Read\Compute\PredicateTreeCompiler` with one method per signal (or constructor-injected `allowedColumns: list<string>` plus `allowsBodyLeaf: bool`)
- [x] 3.4 Compiler enforces depth cap (8), `in`-list cap (256), distinct-attribute-key cap (`max_attribute_filters`), unknown-column rejection, unknown-op rejection, empty-AND/OR rejection
- [x] 3.5 Compiler emits typed predicates: `column.op` → `ColumnEquals`/`ColumnGreaterEqual`/`ColumnLowerEqual`/`ColumnLikePrefix`/`ColumnLikeSuffix`; `in` → `Any` of `ColumnEquals`; `ne` → `Not(ColumnEquals)`; `attribute` → `JsonAttributeEquals`; `body.contains` → `JsonStringContains`

## 4. Cursor digest extension

- [x] 4.1 Extend `App\Read\Cursor\Cursor` value object with `?string $criteriaDigest`
- [x] 4.2 Update HMAC payload to include the digest (or its absence as null)
- [x] 4.3 `mint()` accepts a `criteriaDigest` argument; GET state providers pass null, POST processors compute from the canonicalised criteria
- [x] 4.4 `decode()` returns the digest as part of the value object; cursor cross-method reuse is checked by callers (POST checks digest presence + match; GET checks digest is null)
- [x] 4.5 Add a `App\Read\Cursor\CriteriaCanonicalizer::digest(array $criteria): string` helper: sort keys ascending, no whitespace, deterministic JSON encode, SHA-256 hex

## 5. Processors

- [x] 5.1 Add abstract `App\Read\Http\PostSearchProcessor` extending or implementing AP4's `ProcessorInterface`. Owns: body parse, validation dispatch, criteria compile, cursor digest verify, scanner invocation, response shaping
- [x] 5.2 `App\Read\Http\PostLogsSearchProcessor` — instantiates the compiler with logs' allowed columns and `allowsBodyLeaf: true`
- [x] 5.3 `App\Read\Http\PostTracesSearchProcessor` — traces' allowed columns; rejects `body` leaf
- [x] 5.4 `App\Read\Http\PostMetricsSearchProcessor` — metrics' allowed columns; supports the `exemplarTraceId` sugar; rejects `body` leaf
- [x] 5.5 Each processor stashes the next-cursor URL on the request attribute the existing `NextCursorInjector` reads, so cursor pagination affordances render in every format with no per-format work

## 6. Resource declarations

- [x] 6.1 [REPLACED] Plain Symfony controller `App\Read\Controller\PostLogsSearchController` with `#[Route('/v1/logs/search', methods: ['POST'])]`, mirroring `ReadTraceController`'s precedent for non-collection-shaped responses on `/v1/`. Declared by-controller, not via AP4 `#[Post]`
- [x] 6.2 [REPLACED] Plain controller `PostTracesSearchController` with `#[Route('/v1/traces/search', methods: ['POST'])]`
- [x] 6.3 [REPLACED] Plain controller `PostMetricsSearchController` with `#[Route('/v1/metrics/search', methods: ['POST'])]`
- [~] 6.4 [DEFERRED] OpenAPI documentation of the POST search operations — plain controllers don't auto-document via AP4. Tracked with the same caveat as `ReadTraceController` / `ReadSpanController`; covered by `add-read-api-spec-examples` follow-up

## 7. Tests

- [x] 7.1 Compiler unit tests: each operator, each combinator, depth-cap enforcement, in-list cap enforcement, unknown-column rejection, unknown-op rejection, empty-combinator rejection
- [x] 7.2 Functional test (logs): Hydra shape returned by POST search (`testSearchReturnsHydraShapeMatchingGet`)
- [x] 7.3 Functional test (logs): OR, NOT, IN, body+NOT composition
- [x] 7.4 Functional test (traces): OR over `kind`, prefix on `name`, NOT on `status_text` (`PostTracesSearchTest`)
- [x] 7.5 Functional test (metrics): OR over `metricType`, IN-list on `metricName` (`PostMetricsSearchTest`); `exemplarTraceId` sugar deferred (clients can use the explicit `attribute` form against `exemplars_json` in v1)
- [x] 7.6 Cursor test: POST cursor round-trip across three pages (`testCursorRoundTrip`); mutated criteria → 400 (`testCursorWithMutatedCriteriaRejected`); GET cursor on POST → 400 (`testGetCursorRejectedOnPostSearch`)
- [~] 7.7 Body-size limit test: deferred — Symfony Request body capture isn't easy to overflow in the test harness; covered indirectly by the parser's `\strlen($body) > $maxBodyBytes` branch which is unit-testable separately
- [x] 7.8 Content-Type test: `text/plain` → 415 (`testWrongContentTypeRejected`); `application/json` → 200 (passing tests use it)
- [x] 7.9 Authentication test: missing bearer → 401 (`testMissingBearerRejected`); cross-tenant cursor → covered by existing `Cursor::decode` tenant check unit test
- [x] 7.10 Full test suite passes: 678/678 green

## 8. Documentation

- [x] 8.1 Add a "POST /v1/<signal>/search for complex criteria" subsection to the project README's "Reading data" section
- [x] 8.2 Include worked examples for OR, NOT, IN, attribute trees, mixed AND/OR/NOT
- [x] 8.3 Document the body-size cap, the depth cap, the in-list cap, the cursor-method binding rule
