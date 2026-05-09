## Why

Real OTLP attribute searches almost always combine multiple keys: "RuntimeException raised by checkout-cart in production" is `attribute.exception.type=RuntimeException` AND `attribute.exception.stacktrace?` AND `service=checkout` AND `environment=production`. The shipped read API rejects this with HTTP 400 — `ReadResponseConventionsListener` enforces "at most one `attribute.<key>` filter per request" because the single-attribute path was the v1 cut.

The constraint is artificial. The compute side already supports composing predicates: `JsonAttributeEquals` is one predicate per `(column, key, value)` triple, the scanner ANDs them all per row, and tier ordering already isolates the cheap filters from the JSON-attribute walks. The only gating concern was complexity-of-explanation in the v1 docs, not a missing primitive.

This change lifts the cap to "up to 5 attribute filters per request, AND-composed". That covers the real workload while keeping the per-request cost bounded so a single client cannot DoS the scanner with `?attribute.a=1&attribute.b=2&attribute.c=3&...×100`.

## What Changes

- `App\Read\Http\ReadResponseConventionsListener` no longer rejects requests carrying multiple `attribute.<key>` parameters. It instead enforces a per-request cap (`crashler.read.max_attribute_filters`, default 5).
- `LogsStateProvider`, `TracesStateProvider`, and `MetricsStateProvider` collect every `attribute.<key>=<value>` pair (parsed from the raw query string to preserve dots) and emit one `JsonAttributeEquals('attributes_json', $key, $value)` predicate per pair.
- All `JsonAttributeEquals` instances stay at Tier 4 (post-decode JSON walk). The scanner's existing AND-composition is unchanged; predicates simply compose.
- The 400-response message changes: instead of "At most one `attribute.<key>` per request" it now says "At most %d `attribute.<key>` filters per request" with the configured cap. Above the cap → 400 with the same shape as today.
- `logs-query`, `traces-query`, `metrics-query` specs replace the "two attribute filters rejected" scenario with "five attribute filters compose" and a "six attribute filters rejected" scenario.
- README's "Searching attributes" subsection drops the "one attribute key per request" caveat and explains the cap + AND composition.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `read-api`: relax the "Repeated query parameter rejected" / "Multiple attribute filters" rule for the specific `attribute.<key>` family while keeping repeated-other-param rejection intact.
- `logs-query`: replace the "Two attribute filters in one request rejected in v1" scenario with composing semantics + cap.
- `traces-query`: same treatment as `logs-query`.
- `metrics-query`: same treatment as `logs-query`.

## Impact

- New code: a small change in `ReadResponseConventionsListener` (one branch removed, cap branch added) and one collection-loop in each of three state providers. ~50 lines net.
- Tests: each per-signal functional test gains a "five attribute filters compose" case and a "six rejected" case. The existing single-filter cases continue to pass.
- Config: one new env var `CRASHLER_READ_MAX_ATTRIBUTE_FILTERS` (default 5), surfaced as `crashler.read.max_attribute_filters` parameter.
- No backward-incompatible behaviour: queries that worked in v1 continue to work; queries that were rejected with 400 in v1 may now succeed.
- No new dependencies. Production deploy is additive.
