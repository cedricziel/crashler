## Context

Six v1 read-API changes shipped with deliberate test-coverage cuts. The cuts were rational under each change's time budget but they leave specific behaviours unguarded. This change is purely additive on the test surface — no new endpoints, no spec deltas, no backward-incompatible behaviour.

Stakeholders: future contributors who refactor the read-side compute or HTTP layers and need to know when they break a load-bearing invariant. The tests here are insurance against silent regression on properties the v1 changes proved correct but didn't pin down.

## Goals / Non-Goals

**Goals:**
- One dedicated test per gap, named so a future failure points clearly at the regression.
- Realistic fixtures using the same `LogsIngestService` / `TracesIngestService` / `MetricsIngestService` paths the production write side uses, so the on-disk shapes the tests query are byte-identical to production.
- Minimal production hooks — only when a test cannot otherwise observe the behaviour, and only as `public` accessors with no behavioural change.
- All new tests pass on the first run against current behaviour. (If a test fails, that's a discovered bug; this change pauses to record and fix it.)

**Non-Goals:**
- Adding tests outside the catalogued gap list. Other test improvements are welcome under their own changes.
- Refactoring tests that already exist. The bar is "add the missing one", not "rewrite the old ones".
- Changing the production behaviour the tests assert.

## Decisions

### Decision 1: Multi-file fixtures over multi-row-group-per-file fixtures
**Choice:** the time-window push-down test writes three Parquet files into one partition, each with a single row group whose `time_unix_nano` ranges fall before / inside / after the requested `since`/`until`. Easier to construct than tweaking flow-php's row-group sizing config in tests.

**Why:** the existing `testRowGroupPushDownSkipsFilesViaMinMaxStatistics` already follows this pattern for severity stats. Reuse the helper; avoid the multi-row-group-per-file plumbing.

### Decision 2: Schema-absent column test goes through the real flow-php Schema
**Choice:** instead of mocking `Schema` and `RowGroup`, write a Parquet fixture for the *traces* signal and run a logs-only predicate (`severity_number`) against it. The `RowGroupSkipper` will see `severity_number` is not in the traces schema → indeterminate → no skip.

**Alternative considered:** fully mocked test using `createStub` on flow-php classes. Rejected — flow-php classes have non-trivial constructors and the mock setup would obscure intent.

**Why:** the test reads more like "if a predicate references a column the schema doesn't have, the scanner falls through" than "here's how the mock factory works". Faster to write, faster to read, less brittle.

### Decision 3: Cardinality-cap test synthesizes attributes at write time
**Choice:** the aggregate cardinality test writes >200 distinct service names directly into the partition (one log per distinct service), then aggregates with `groupBy=service`.

**Why:** real-world cardinality typically comes from attribute-key explosion; the v1 group-by allow-list is typed columns only, so we need typed-column variation to trigger the cap. 201 distinct service names is straightforward to write.

### Decision 4: Body-size 413 test bypasses zenstruck/browser
**Choice:** call the `PostSearchRequestParser` directly with a synthetic `Request::create()` carrying a >64 KiB body. zenstruck/browser tightens the request body in ways that complicate exercising the byte cap.

**Why:** this is the same pattern `ReadResponseConventionsListenerTest` uses for its repeated-param test (BrowserKit collapses dupes). Direct parser invocation is clean, fast, and exercises the production code path.

### Decision 5: Multi-attribute tests for traces and metrics use real fixtures
**Choice:** each test writes a 3-row fixture with two distinct attributes per row covering the {neither, one, both} matrix, then asserts only the "both match" row is returned.

**Why:** mirrors the `MultiAttributeFiltersTest` for logs that already exists. Keeps the assertion shape identical across signals so regressions are spot-checkable.

## Risks / Trade-offs

- **Risk: a "should pass" test fails on first run.** That's a real-world bug discovery, not a test bug. → Mitigation: when this happens, this change pauses, records the bug as a fixable production issue, and either fixes it inline or pushes the test under a `markTestSkipped` with the bug ticket.

- **Risk: tests get flaky over real fixtures.** The MockClock + StubFilenameGenerator pattern used by the existing functional tests is deterministic; following it carries that determinism forward.

- **Trade-off: each test re-writes a small fixture, which is slower than a shared fixture pool.** → Mitigation: the test runtime overhead is negligible (each test ~50ms) and the isolation benefits (no cross-test contamination) outweigh the speed cost.

- **Trade-off: not adding the deferred traces/metrics aggregate tests.** Per the proposal, the aggregate-functional surface for traces/metrics is intentionally scoped to a separate change because the controller is structurally identical to logs and the regression risk is "no per-signal-only logic" rather than "different code paths".

## Migration Plan

- No deployment. No data migration.
- Roll-forward: tests merge; CI starts running them; future regressions caught.
- Roll-back: revert. No fallout.

## Open Questions

- Should the schema-absent test also exercise the inverse case (column present but with null statistics)? → out of scope here; the v1 RowGroupSkipper already handles that branch and `testRowGroupPushDownLeavesStringPredicatesAlone` exercises it indirectly. A dedicated null-stats test is its own item.
- Should the cardinality test cover the `groupBy=service` path AND a metric aggregation? → only the logs path in v1 of this change; the metric/trace cardinality is structurally identical and the deferred aggregate-traces/metrics work covers it later.
