## Why

The 2026-05-09 read-API batch shipped six changes with deliberate scope cuts on test coverage. Each cut was reasonable in isolation — "the per-signal logic is identical, the logs functional test exercises it"; "the parser branch is unit-testable separately"; "covered indirectly by other tests". Aggregated across six changes, those cuts add up to about a dozen specific gaps where the v1 behaviour ships without a dedicated regression test.

A regression test isn't insurance against the original bug — that's already gone. It's insurance against future refactors silently breaking the same property and shipping anyway because no test caught it. The deferred items here are exactly the high-value places where "if this regresses, we'd really want to know on PHPUnit, not from a customer report".

This change closes the gap-fill tests in one focused pass. No new behaviour, no new endpoints — pure test additions plus any tiny hooks needed to make a behaviour testable.

## What Changes

Six new functional/unit tests, organized by what they cover:

- **Time-window row-group push-down test**: dedicated multi-hour fixture across partitions to assert leading-window groups are skipped when their `time_unix_nano` stats fall before `since`. (Promoted from `add-read-rowgroup-pushdown` task 4.2.)
- **Schema-absent column test**: unit test for the `RowGroupSkipper` against a row group whose schema does not include the predicate's column — verifies the indeterminate path returns false (no skip). (Promoted from `add-read-rowgroup-pushdown` task 4.3.)
- **Multi-attribute traces test**: functional test for `GET /v1/traces?attribute.k=v&attribute.l=w` AND-composition over a span fixture. (Promoted from `add-read-multi-attribute-filters` task 4.5.)
- **Multi-attribute metrics test**: functional test for `GET /v1/metrics?attribute.k=v&attribute.l=w` AND-composition over a metric fixture. (Promoted from `add-read-multi-attribute-filters` task 4.5.)
- **POST search body-size 413 test**: synthesize a >64 KiB POST body, assert 413 with cap-naming message. (Promoted from `add-read-post-search` task 7.7.)
- **Aggregations cardinality cap test**: write a fixture with >200 distinct group keys, run the aggregate endpoint, assert 400 with cap-naming message. (Promoted from `add-read-aggregations` task 6.6.)

The traces/metrics aggregate functional tests, exemplarTraceId sugar test, and compat cross-tenancy test from the original deferred list are punted to follow-ups (the aggregate test scope is "logs is the canonical case; traces/metrics are deferred" intentionally; the exemplarTraceId sugar isn't shipped in v1 of POST search; compat cross-tenancy depends on the search endpoints which haven't shipped).

## Capabilities

### New Capabilities

(none — pure test additions)

### Modified Capabilities

(none — the existing requirements stay verbatim; this change exercises them)

## Impact

- New code: ~6 new test files (~600 lines total).
- No production code changes expected — but if a test reveals a missing seam, a tiny hook on the production class is allowed (e.g., a public accessor for an internal counter). Such hooks are explicitly noted in this change's design.md.
- Test-runtime delta: small; each new test runs in O(ms) once the kernel is warm.
- No backward-incompatible changes. No spec deltas.
- Risk: a test that "should pass" against current behaviour might reveal an unintentional bug. That's the value — flag it, fix it under this change.
