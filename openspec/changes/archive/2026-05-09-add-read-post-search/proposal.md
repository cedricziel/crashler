## Why

The v1 read API answers the question "give me events matching these criteria, expressed as URL parameters". URL parameters are great for the dashboard-and-saved-link case, awkward for the everything-else case:

- **OR-composition**: "logs from `checkout` OR `payments`" — there is no GET grammar for this short of repeated keys, which we forbid.
- **Negation**: "logs not from `internal`" — same problem.
- **Long IN-lists**: "logs whose traceId is one of [200 IDs from a backfill report]" — would blow past most servers' URL length limits and is unreadable in browser history anyway.
- **Nested attribute predicates**: "any attribute key matching `db.*` whose value contains `timeout`" — there is no compositional URL grammar that survives in URL form.
- **Body filters**: today `bodyContains` is a single substring; complex log-content matching is impossible from a URL.

The existing GET endpoints stay as-is for the simple, cacheable, shareable case. This change adds a `POST /v1/<signal>/search` endpoint per signal that takes a JSON body describing the criteria as a small, predicate-tree DSL. The endpoint produces the same per-row hypermedia and cursor pagination the GET endpoints produce; the only thing that changes is how the criteria arrive.

This pattern is well understood: Loki has `POST /loki/api/v1/query_range`, Grafana Tempo has `POST /api/search`, Elastic has DSL bodies. We're not inventing the shape, we're following it.

## What Changes

- New endpoint: `POST /v1/logs/search`, `POST /v1/traces/search`, `POST /v1/metrics/search`. Each takes a JSON body with shape:
  ```json
  {
    "since": "1h",
    "until": null,
    "limit": 100,
    "cursor": null,
    "criteria": { ... predicate tree ... }
  }
  ```
- Predicate tree DSL: a small JSON shape that compiles to the existing `App\Read\Compute\Predicates\*` classes plus two new boolean combinators (`Any` for OR, `Not` for negation). Leaves are `column` / `attribute` / `body` predicates with a small set of operators (`eq`, `ne`, `gte`, `lte`, `in`, `prefix`, `suffix`, `contains`).
- Response shape: identical to the corresponding GET (Hydra/HAL/JSON/JSON:API negotiated, per-row `_links`, cursor pagination).
- The HMAC-signed cursor format gains a `criteria` digest so a cursor minted by a POST search continues that search on follow-up POST calls. Cursors minted by GET cannot be replayed against POST and vice-versa (a digest mismatch returns 400).
- Bearer auth, tenant scoping, time-window cap, page-size cap, execution-timeout: unchanged from GET.
- Wire format negotiation: same four formats. The body's `criteria` is consumed as JSON regardless of `Accept`.
- Documentation: the OpenAPI spec adds the three POST operations; their request body is a JSON Schema for the predicate tree. The Resource classes declare the operations via `#[Post(uriTemplate: '/{plural}/search', read: false, deserialize: false, ...)]` plus a custom processor.

## Capabilities

### New Capabilities

(none — this is a new operation on existing capabilities)

### Modified Capabilities

- `read-api`: adds the POST search endpoint family, the JSON-body predicate-tree DSL, and the cursor digest extension.
- `logs-query`: declares the `POST /v1/logs/search` operation on the Log Resource and binds the same logs-specific filter set.
- `traces-query`: declares the `POST /v1/traces/search` operation.
- `metrics-query`: declares the `POST /v1/metrics/search` operation.

## Impact

- New code:
  - 3 `#[Post]` operation declarations on the existing Resources.
  - 1 shared `App\Read\Http\PostSearchProcessor` (or three thin per-signal processors) that deserialises the body, compiles the DSL into predicates, and dispatches to the existing `ParquetScanner`.
  - 1 DSL compiler `App\Read\Compute\PredicateTreeCompiler` plus two new combinators `Any` (OR) and `Not` (NOT).
  - 1 schema validator for the JSON body (Symfony Validator constraints, no third-party JSON Schema lib).
- Tests: per-signal functional tests for OR, NOT, IN-list, mixed nesting, malformed body (400), oversize body (413 via existing limit), unknown operator (400), and cursor round-trip across two POST calls.
- Config: optional `crashler.read.post_search.max_body_bytes` (default 64 KiB) to bound the request body size.
- No backward-incompatible behaviour: GET endpoints unchanged. POST is purely additive.
- No new dependencies. AP4 already supports `#[Post]` operations with custom processors.
- Operational risk: POST bodies introduce a new attack surface (request smuggling, malformed JSON). The JSON-decode + Symfony Validator path is already battle-tested via the OTLP/HTTP-JSON ingest path; we reuse the framework primitives rather than rolling our own.
