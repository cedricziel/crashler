## Context

The v1 read API enforces a one-attribute-filter-per-request cap, returning 400 for any request that carries two or more `attribute.<key>=<value>` parameters. The cap was a conservative cut — the scanner already supports per-row AND composition of predicates, and `JsonAttributeEquals` is parameterised on `(column, key, value)`, so adding a second equals filter is structurally trivial. The cap shipped as a guard against unbounded `attribute.x_1=…&attribute.x_2=…&…` query strings, not because the implementation could not handle them.

Real workloads disagree with the cap. The two most common attribute-filter shapes we see internally are:
- "Find errors where the exception type is X" → at minimum `attribute.exception.type=X` paired with a `severityNumberMin=17` and a `service=` filter
- "Find a span by attributes" → `attribute.http.method=POST` AND `attribute.http.route=/checkout`

Both shapes are illegal under v1. Operators currently work around them by dropping one filter and post-filtering client-side, which costs both bandwidth and developer trust.

The natural ceiling: *which* attribute filters are worth supporting in a single request? Looking at the OTel semantic conventions, a typical span carries 5–15 attributes; a typical log record carries 2–8. A request asking for more than 5 attribute matches at once is more likely a typo or a pathological test than a real query. Five is the cap we'll enforce; any value > 5 returns 400 with a "narrow your filters" message, mirroring the existing window-cap response.

## Goals / Non-Goals

**Goals:**
- Lift the artificial cap so the most common attribute-search shapes succeed.
- Keep the request bounded so the worst case is bounded JSON-decode work, not unbounded.
- Preserve every other validation we have today (repeated *non-attribute* query params still fail).
- Keep the on-disk and predicate-tier semantics unchanged: each filter still compiles to a Tier-4 `JsonAttributeEquals`, predicates AND-compose per row, the JSON walk runs only after Tier 0/1/2 have already eliminated rows.

**Non-Goals:**
- OR-composition (`attribute.x=a OR attribute.y=b`). Out of scope; tracked under `add-read-post-search`.
- Negation (`attribute.x!=v`). Out of scope.
- Substring / regex on attribute values. Out of scope; the existing `JsonAttributeEquals` is exact-match only.
- Numeric or boolean attribute values. The OTLP `AnyValue` walk in `JsonAttributeEquals` already handles these by stringifying; no change.
- Attribute filters on `resource_attributes_json`. Today's filter is on `attributes_json` (the per-record attributes column). Resource attributes are filtered via the typed top-level columns (`service`, `environment`, `host`); `add-read-resource-attribute-filters` would be a separate change.

## Decisions

### Decision 1: Fixed cap of 5 (configurable)
**Choice:** default `crashler.read.max_attribute_filters = 5`, env-overridable via `CRASHLER_READ_MAX_ATTRIBUTE_FILTERS`. The 400 response message references the configured cap by name.

**Alternative considered:** unbounded with a worst-case timeout fallback. Rejected — would let one client request consume the whole `crashler.read.execution_timeout_seconds` budget on JSON decoding alone.

**Why:** five accommodates the real-world "common case + a couple of niche keys" shape and bounds the worst-case JSON-walk cost at 5×N rows where N is the rows surviving Tiers 0–3.

### Decision 2: Parse attribute filters from the raw query string
**Choice:** continue to read attribute filters from `Request::getRequestUri()` rather than `Request::query`. PHP's `parse_str` collapses dots in parameter names to underscores, so `attribute.exception.type` becomes `attribute_exception_type` in `$_GET`. The state providers already work around this by walking the raw query string; this change keeps that path and just stops bailing out on count > 1.

**Why:** changing the query parser would be a much wider refactor; the raw-string walk is already working in v1 single-filter mode and is known-good.

### Decision 3: AND composition only; multiple values for the same key are rejected
**Choice:** `attribute.exception.type=A&attribute.exception.type=B` returns 400 with a "repeated parameter" message. Different keys compose; the same key twice is treated as the v1 "repeated query parameter" violation.

**Why:** per-key OR is structurally a distinct compute shape; the spec already promises "repeated query parameter rejected" so we keep that promise. Operators wanting OR-of-values get the bigger hammer (`add-read-post-search`) when that ships.

### Decision 4: Listener enforces the count; state providers materialise the predicates
**Choice:** the count check stays in `ReadResponseConventionsListener` (the existing 400-source) so a malformed request gets rejected before any state provider runs. The state providers iterate the same raw-string parse and emit one `JsonAttributeEquals` per pair.

**Alternative considered:** centralise both the parse and the cap in a single helper. Deferred — the listener already owns the raw-string walk for the count, and the state providers already own the predicate emission. Two responsibilities, two homes. Refactor is welcome but not in this change.

**Why:** smaller blast radius, and the listener-vs-provider split mirrors how Symfony's request lifecycle works (rejection at request time, work at provider time).

### Decision 5: Order of attribute-filter predicates
**Choice:** preserve the order they appear in the query string. `JsonAttributeEquals` is at Tier 4; intra-tier ordering today is "as-emitted". This change does not promise selectivity-driven reordering.

**Why:** keeps the implementation behavioural; selectivity-driven reordering is a global concern (`add-read-rowgroup-pushdown` is the only push-down we ship in this milestone), not a per-tier concern.

## Risks / Trade-offs

- **Risk: Tier-4 cost grows linearly with the filter count.** Five `JsonAttributeEquals` per row is 5× the JSON-walk cost of one. → Mitigation: the row count reaching Tier 4 is already small after Tier-0 partition pruning + Tier-2 typed-column filtering. The `crashler.read.execution_timeout_seconds` (10s default) caps the worst case. We accept the 5× factor.

- **Risk: a request just under the cap can still time out.** Five attribute filters on a wide partition with no other selective predicates can exhaust the budget. → Mitigation: the existing 504 response. Operators learn the same lesson they already learn for `bodyContains` — combine with `service=` or a tighter time window.

- **Risk: query-string ambiguity from BrowserKit / mock clients.** The `zenstruck/browser` test client collapses repeated query parameters before they reach the listener (we hit this in v1). → Mitigation: tests for the cap-violation path use synthetic `Request::create()` directly, as the v1 unit tests do.

- **Trade-off: cap is a magic number.** Five is empirical, not derived. → Mitigation: it's configurable; if a real workload calls for more, raise the cap rather than rewriting compute.

## Migration Plan

- No data migration. No schema migration.
- Roll-forward: deploy normally. The new env var is optional; the default of 5 ships in code.
- Rollback: revert. Behaviour returns to the v1 "one attribute per request" cap.
- Communication: the README change explains the new cap; no operator action is required.

## Open Questions

- Should `attribute.<key>` ever support negation in v1.5 (e.g., `attribute.exception.type!=Boom`)? Out of scope here. Probably belongs to `add-read-post-search` because the URL grammar gets ugly fast.
- Should we expose the count cap via the OpenAPI schema (e.g., `maxItems` on a synthesised `attributes` array)? OpenAPI doesn't have a clean way to express "the parameter family `attribute.<key>` is capped at N members". For now we document the cap in the resource description and the 400 message.
