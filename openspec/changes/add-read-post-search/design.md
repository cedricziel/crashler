## Context

The read API today answers a narrow band of queries: equality, simple ranges, prefix/suffix wildcards, single-attribute equality. Everything outside that band — OR, NOT, IN, complex attribute trees — has no URL grammar and is rejected at the listener or simply unrepresentable.

Stakeholders:
- **Operators** running incident response. They have one giant alert and need "logs from {checkout, payments, billing} with severity >= ERROR in the last 30 minutes containing exception.type=DownstreamTimeout". GET cannot express the OR over services.
- **Backfill / migration tooling**. They have lists of trace IDs and want every span. GET cannot carry 200 IDs cleanly.
- **Future query DSLs**. Loki's LogQL, Tempo's TraceQL, ES Query DSL. We do not commit to a query language in this change, but the predicate-tree DSL is the seam where one could compile down.

The compute side is already a tree of predicates that compose with AND. Adding OR and NOT combinators is a structural extension — not a rewrite — provided we keep the tier-ordering contract: cheap predicates evaluate before expensive ones, and tier ordering must be derivable from the predicate tree.

## Goals / Non-Goals

**Goals:**
- One POST endpoint per signal: `/v1/logs/search`, `/v1/traces/search`, `/v1/metrics/search`.
- A small, declarative JSON body grammar that maps 1:1 onto the existing predicate classes plus `Any` (OR) and `Not` (NOT).
- Identical response semantics to the corresponding GET: same content negotiation, same per-row hypermedia, same cursor pagination, same time-window mandate.
- Cursor parity: a cursor minted by a POST search resumes that search on subsequent POST calls. Cursors are not interchangeable across HTTP methods.
- Defensive boundaries: capped body size, capped predicate-tree depth, capped IN-list size, capped attribute-key set.

**Non-Goals:**
- A query language. The DSL is a JSON tree, not a string. LogQL/TraceQL compilation is a future concern.
- Aggregations. Group-by / count / sum live in `add-read-aggregations`.
- Read-write convergence. POST is for *querying*; the OTLP write path stays at `/v1/logs`, `/v1/traces`, `/v1/metrics`.
- Streaming / chunked responses. Same 200-OK-with-body shape as GET. If we ever stream, that is its own change.
- Saved searches / named queries / server-side query catalogs. Out of scope.
- Compute-engine choice. Scanner stays the same; `ScansParquet` interface is still hypothetical.

## Decisions

### Decision 1: POST instead of GET-with-body or any other shape
**Choice:** `POST /v1/<plural>/search`. The "search" suffix is part of the path, not a query parameter, so AP4 can route it to a distinct operation without colliding with the GET collection at `/v1/<plural>`.

**Alternatives considered:**
- **GET with body**: HTTP allows it but middleboxes mangle it. We already reject GET-with-body via `ReadResponseConventionsListener`. Continuing to forbid it is the consistent choice.
- **POST /v1/<plural>** (overloading the collection URL): some frameworks do this; AP4 also supports it. Rejected because the OTLP write side already owns POST on `/v1/logs`, `/v1/traces`, `/v1/metrics` and that semantic clash is exactly the kind of "is this an ingest or a search?" confusion we want to avoid.
- **POST /search**: rejected — single endpoint that polymorphs on a `signal` field is harder to OpenAPI-document and harder to route through API Platform's per-Resource processor model.

**Why:** the `/<plural>/search` shape is signposted, mirrors Tempo / Elastic conventions, and slots into AP4's per-operation processor model cleanly.

### Decision 2: A predicate-tree JSON DSL, not a query language
**Choice:** the request body's `criteria` field is a small JSON tree:

```jsonc
// All AND
{"all": [
  {"column": "resource_service_name", "op": "eq", "value": "checkout"},
  {"column": "severity_number", "op": "gte", "value": 17}
]}

// Any OR
{"any": [
  {"column": "resource_service_name", "op": "eq", "value": "checkout"},
  {"column": "resource_service_name", "op": "eq", "value": "payments"}
]}

// IN as syntactic sugar
{"column": "trace_id_hex", "op": "in", "value": ["abc…", "def…"]}

// Negation
{"not": {"column": "resource_service_name", "op": "eq", "value": "internal"}}

// Attribute walk
{"attribute": "exception.type", "op": "eq", "value": "RuntimeException"}

// Body substring
{"body": "contains", "value": "panic"}
```

**Alternatives considered:**
- **String DSL** ("LogQL-lite"): more readable, but requires a parser. Out of scope; can compile to this tree later.
- **Fully recursive `{op, args}` shape** like Mongo's query language: equally expressive but obscures column-vs-attribute-vs-body distinction. Our shape names the kind explicitly.

**Why:** a JSON-tree shape is mechanically easy to validate and translate into our existing predicate classes. It does not pretend to be a query language; it does pretend to be a deterministic intermediate representation.

### Decision 3: Compiler emits predicates with the same Tier classification
**Choice:** `PredicateTreeCompiler` walks the JSON tree and emits a `list<Predicate>`-or-`AndPredicate(predicates: …)` plus the new `Any(predicates: …)` and `Not(child: …)` combinators. The scanner's tier-ordering rule applies to the leaves: at every level, leaves with lower tiers evaluate first.

For OR, the combined tier is the *maximum* of children's tiers (because OR cannot short-circuit until the cheapest child has been evaluated against all rows that could match). This is conservative but correct.

For NOT, the tier is the same as the child.

**Why:** keeps the spec's "cheap-before-expensive" promise without compromising correctness. Tier numbers stay integers, the mapping rule is monotonic.

### Decision 4: Cursor format extends with a `criteria` digest
**Choice:** the existing HMAC-signed cursor structure (tenant, since, until, limit, position) gains a `criteria_digest` field — a SHA-256 hex of the canonicalised criteria JSON. On follow-up POST search, the server recomputes the digest from the new request body and rejects with 400 if it differs.

GET cursors continue to set `criteria_digest = null`; POST search rejects them by checking that the digest is non-null. POST cursors are likewise rejected by GET state providers (which only consult tenant + window + position fields).

**Alternatives considered:**
- **Embed the entire criteria tree in the cursor**: bloats the cursor. Rejected — digest is sufficient for "same query continues" and keeps the cursor compact.
- **Server-side query store**: requires durable state. Rejected — we are file-only by design.

**Why:** stateless, tamper-evident, and prevents cursor swapping between unrelated queries.

### Decision 5: Body shape and limits
**Choice:**
- `since`/`until`/`limit`/`cursor` mirror the GET parameters.
- `criteria` is the predicate tree.
- Max body size: 64 KiB (`crashler.read.post_search.max_body_bytes`). Above the cap → 413.
- Max tree depth: 8 (Cap on AND/OR/NOT nesting).
- Max `in` list size: 256.
- Max distinct `attribute` predicates: same cap as the GET endpoint (default 5 from `add-read-multi-attribute-filters`).

**Why:** the worst-case scanner cost grows with tree size. Bounding each axis bounds the worst case at "few KiB of JSON to parse, ~512 leaves to evaluate per row".

### Decision 6: Operations declared on existing Resources, not new Resources
**Choice:** add `#[Post(uriTemplate: '/{plural}/search', name: 'search', deserialize: false, read: false, processor: PostSearchProcessor::class, ...)]` to `Log`, `Trace`, `Metric` Resource classes. `deserialize: false` lets us own the body parsing; `read: false` skips AP's default item lookup.

**Why:** the response shape is the same Resource collection, so the Resource is the right place to declare the operation. Adding a new Resource just for "search" would duplicate property mapping and content negotiation.

### Decision 7: Per-signal processors share a base
**Choice:** `App\Read\Http\PostSearchProcessor` (abstract) handles body parsing, validation, cursor digest, hand-off to a `ScansParquet` invocation. Three thin subclasses (`PostLogsSearchProcessor`, `PostTracesSearchProcessor`, `PostMetricsSearchProcessor`) provide signal-specific allowed columns and signal-specific compilation rules.

**Alternatives considered:**
- One processor that branches on the Resource class. Rejected — AP4 wires processor per operation; the explicit per-signal class makes test seams obvious.

## Risks / Trade-offs

- **Risk: body validation gaps could let a malformed tree crash the scanner.** → Mitigation: Symfony Validator constraints on every node type; reject on first violation with 400 and a JSON-pointer-style error message. Component test for every constraint.

- **Risk: predicate-tree depth is a DoS vector.** → Mitigation: depth cap of 8 enforced during compilation, exceeded → 400.

- **Risk: cursor swapping** (re-using a GET cursor against POST or vice-versa). → Mitigation: digest field; missing-or-mismatched → 400.

- **Risk: processor leaks the OTLP write path's input shape into search.** → Mitigation: distinct DTOs (`PostSearchRequestDto`, etc.) in `App\Read\Http\Dto`; never reuse the OTLP JSON DTOs.

- **Trade-off: OR cannot push down today.** A row-group is skipped only when *all* numeric predicates in the AND-conjunction refute it. Once OR appears, conservative skip logic is "skip iff every child of the OR refutes" — implementable but more complex. → Mitigation: v1 of POST search punts on row-group push-down for OR-containing trees; the per-OR conservative push-down is a follow-up if profiling shows it pays.

- **Trade-off: documentation cost.** The OpenAPI body schema is more elaborate than per-parameter declarations. → Mitigation: a single nested `oneOf` over leaf node types covers the grammar in well under 200 lines of OpenAPI.

## Migration Plan

- No data migration. No on-disk change.
- Roll-forward: deploy normally. New endpoints become available immediately. Existing GET endpoints unchanged.
- Rollback: revert. POST endpoints become 404. GET continues to serve.
- Communication: README "Searching" section adds a "POST /v1/<signal>/search for complex criteria" subsection with worked examples for OR, NOT, IN, attribute trees.

## Open Questions

- Should the response of POST search carry a `Vary: Accept, Authorization, Origin, Content-Type` header set as GET does? Yes — same conventions listener applies.
- Should we accept a `format=jsonld` body field as a synonym for the `Accept` header? No — `Accept` is the canonical content-negotiation channel, no need for a second knob.
- Should the OTLP-shape Trace.Get gain a `POST /v1/traces/search/by-criteria` that returns OTLP-shaped trees? Out of scope — Trace.Get is already a single-trace lookup, the search endpoint is for multi-row collection responses.
- Should we expose tier numbers in the response for debugging? No — same reasoning as `groupsSkipped` in the row-group push-down change: instrumentation belongs behind a separate feature flag.
