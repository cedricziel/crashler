## 1. Configuration

- [x] 1.1 Added `crashler.read.max_attribute_filters` parameter (default 5) wired to env `CRASHLER_READ_MAX_ATTRIBUTE_FILTERS`
- [x] 1.2 Bound to env var with default 5; documented in README's per-signal filters subsection
- [x] 1.3 README documents the env var alongside the cap

## 2. Listener

- [x] 2.1 `ReadResponseConventionsListener`: replaced the "at most one attribute.<key>" branch with a count-and-cap check using the configured cap
- [x] 2.2 "Same attribute key repeated" continues to be rejected via the existing "repeated parameter" 400 message naming the offending key (no separate branch needed — the `seen[$name]` check already handles same-key duplicates including same-attribute-key)
- [x] 2.3 Over-cap message: "At most %d `attribute.<key>` filters per request" with the cap interpolated
- [x] 2.4 Cap injected via constructor argument bound to `%crashler.read.max_attribute_filters%`

## 3. State providers

- [x] 3.1 [REPLACED] Multi-attribute filter compilation extracted to `BaseSearchStateProvider::extractAttributeFiltersFromRequestUri()` and added to the common predicate compilation in `provide()`. Logs/Traces/Metrics state providers don't need per-signal changes — every signal filters `attributes_json` the same way
- [x] 3.2 [COVERED] Same as 3.1 — traces inherit via base
- [x] 3.3 [COVERED] Same as 3.1 — metrics inherit via base
- [x] 3.4 `BaseSearchStateProvider` continues to pass the predicates list through unchanged; the new attribute-filter predicates are appended after `compilePerSignalPredicates`

## 4. Tests

- [x] 4.1 Updated `HttpConventionsTest::testMultipleAttributeFiltersInOneRequestRejected` → renamed to `testMultipleAttributeFiltersInOneRequestAccepted`, asserts 200 instead of 400
- [x] 4.2 Added `ReadResponseConventionsListenerTest::testMultipleDistinctAttributeFiltersAccepted` and `testAttributeFilterCapExceeded` (six keys → 400) and `testRepeatedSameAttributeKeyRejected`
- [x] 4.3 [COVERED] Repeated-same-key already rejected; covered by `testRepeatedSameAttributeKeyRejected`
- [x] 4.4 Added `MultiAttributeFiltersTest::testTwoAttributeFiltersComposeWithAnd` — fixture with 3 rows that match neither/one/both attributes; asserts only the row matching both is returned
- [~] 4.5 [COVERED-FOR-LOGS] Per-signal functional tests for traces and metrics deferred — the AND-composition lives in `BaseSearchStateProvider` and is identical for all signals, exercised by the logs test and the listener-cap unit tests
- [x] 4.6 Full test suite: 683/683 green

## 5. Documentation

- [x] 5.1 README "Per-signal filters" subsection documents `attribute.<key>=<value>` family with the AND-composition rule and the configurable cap
- [x] 5.2 README notes that repeating the same attribute key remains a 400 and points operators at `POST /v1/<signal>/search` for OR-of-values
