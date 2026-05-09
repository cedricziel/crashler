## Context

Grafana ships built-in data sources for Tempo, Loki, and Prometheus. Each speaks an HTTP API specific to its upstream project. None of them speak Hydra/HAL/JSON:API natively. Operators who already have Grafana dashboards have a binary choice: rebuild against `/v1/`, or run the upstream system alongside Crashler.

A third path exists: a thin shim. The shim does not promise to reimplement the upstream API — it promises to make a Grafana data source's *most common request shapes* work, returning *responses shaped enough* that Grafana's panels render correctly. The shim is explicitly partial; the partiality is documented; operators see exactly which features are present and which return 400.

Stakeholders:
- Operators with existing Grafana dashboards (the primary audience)
- Future contributors who might want to add a Honeycomb / Jaeger / Lightstep shim (the umbrella `compat-shims` capability gives them a template)
- Internal team — we want the shims behind feature flags so they do not become unfunded surface area

The shims share enough plumbing (auth, tenancy, time-window resolution, the underlying scanner) that they belong under one umbrella capability — but each upstream's quirks (Tempo's `traceID` vs Loki's `time` vs Prometheus's `step`) are different enough that they deserve sub-capabilities.

## Goals / Non-Goals

**Goals:**
- A working Grafana Tempo data source against `/compat/tempo/`.
- A working Grafana Loki data source against `/compat/loki/` with label browser + `query_range` for the simple `{label="value"} |= "substring"` shape.
- A working Grafana Prometheus data source against `/compat/prom/` for the documented PromQL subset.
- An umbrella capability that documents the cross-shim contract: auth, tenancy, timeout, what is and is not preserved, version pinning.
- Feature flags: every shim is OFF by default; operators opt in.
- Hard, polite, document-driven 400s for any upstream syntax we don't implement.

**Non-Goals:**
- Full PromQL. We support a deliberately small subset; everything else returns 400 with a message naming the supported forms.
- Full LogQL. Same — `{label="value"}` selectors and `|= "substring"` line filters only.
- Tempo's TraceQL. Out of scope; the Tempo shim covers attribute/time/duration search via the GET-search-equivalent query parameters Grafana sends, not TraceQL.
- Streaming responses. Grafana's data sources support streaming for live tail; we punt.
- Recording rules / alerting rules. Out of scope.
- Multi-tenancy headers (Tempo's `X-Scope-OrgID` etc.) — the existing Bearer-token tenant model is the only auth.
- Performance parity with the upstream systems. The shim is correctness-shaped, not benchmark-shaped.

## Decisions

### Decision 1: One umbrella capability, three sibling capabilities
**Choice:** `compat-shims` defines the umbrella contract. `compat-tempo`, `compat-loki`, `compat-prometheus` define each shim's specific endpoint set, query shapes, response shapes, and explicit non-preservations.

**Why:** lets us write a single requirement for "all shims share auth, tenancy, timeout" and avoid copy-pasting it three times. Future shims (Jaeger, Zipkin, etc.) extend the umbrella without touching the existing siblings.

### Decision 2: Path scheme `/compat/<vendor>/<vendor's path>`
**Choice:** Tempo lives under `/compat/tempo/api/...`, Loki under `/compat/loki/api/v1/...`, Prometheus under `/compat/prom/api/v1/...`. The vendor's own path scheme is preserved verbatim under our prefix.

**Alternative considered:** `/v1/compat/...` — rejected because it implies the shim is part of the v1 read API contract; it isn't.

**Why:** preserving the vendor's path scheme means operators' existing Grafana data source URL templates work with a single base-URL change.

### Decision 3: Plain Symfony controllers, not API Platform Resources
**Choice:** the shims are not Resources. They are controllers in `App\Read\Compat\<Vendor>\*` that do their own JSON shaping. Reasons:

- The response shapes do not look like our Resource shapes — Tempo's `traces[]` carries Tempo-specific keys; Loki's `streams[]` is its own envelope.
- Content negotiation is fixed — each shim returns `application/json` only. There is no Hydra / HAL alternative.
- The OpenAPI document is not the consumer contract here; the upstream documentation is.

**Why:** AP4 is the right tool for shapes we own; controllers are the right tool for shapes we are imitating from elsewhere.

### Decision 4: Tempo shim — minimum viable Grafana surface
**Choice:** three endpoints:

- `GET /compat/tempo/api/echo` → returns `200 echo` (Grafana's connection test).
- `GET /compat/tempo/api/search?...` → translates Grafana's query parameters (`tags`, `minDuration`, `maxDuration`, `start`, `end`, `service.name`, etc.) into the same predicate compiler used by `GET /v1/traces` and returns Tempo's `{traces: [{traceID, rootServiceName, rootTraceName, startTimeUnixNano, durationMs, ...}]}` shape.
- `GET /compat/tempo/api/traces/{traceId}` → reuses the existing `GET /v1/traces/{traceId}` Trace.Get with `Accept: application/otlp+json`. Returns the OTLP-shape JSON which Grafana's Tempo data source already accepts.

**Why:** these three are the minimum to get a Grafana Tempo data source's "Test connection", "Search", and "View trace" workflows green.

### Decision 5: Loki shim — selector + line filter only
**Choice:** four endpoints:

- `GET /compat/loki/api/v1/labels` → returns `{status: "success", data: ["service", "environment", "host", "severityText", ...]}` — the closed list of label names we expose.
- `GET /compat/loki/api/v1/label/{name}/values` → for label `service`, scans the partition root and returns distinct service names within the time window. Bounded by the same time-window cap.
- `GET /compat/loki/api/v1/query_range?query={service="checkout",severityText="ERROR"} |= "panic"&start=...&end=...&step=...` → tiny LogQL parser:
  - `{key="value", key="value", ...}` selector (label equality, AND-composed).
  - Optional `|= "substring"` line filter (compiles to `JsonStringContains('body_json', "substring")`).
  - `step` is honoured as a "downsampling" hint — we cap the result at `step`-aligned timestamps but do not aggregate.
  - Anything else (regex selectors `=~`, label filters `| label="..."`, format expressions, range vectors `[5m]`, aggregations `sum / count_over_time`) → 400 with a message naming the supported subset.
- `GET /compat/loki/api/v1/series` (optional, low priority) → returns the unique label sets present.

**Why:** these four cover Grafana's Loki data source's most common Explore session: "browse labels, run a simple selector query, optionally filter by substring".

### Decision 6: Prometheus shim — explicit "count and sum only" subset
**Choice:** three endpoints:

- `GET /compat/prom/api/v1/labels`, `GET /compat/prom/api/v1/label/{name}/values` — same scheme as Loki for the metric label namespace.
- `GET /compat/prom/api/v1/query_range?query=count_over_time({metric="http.server.request.duration"}[1m])&...` and `query=sum by (service) ({metric="..."})`. The PromQL parser handles only:
  - `<selector>` raw point selection.
  - `count_over_time(<selector>[<range>])` — count with built-in time bucketing.
  - `sum by (<labels>) (<selector>)` — group-by sum.
  - All values are rendered into Prometheus's `{status: "success", data: {resultType: "matrix", result: [{metric: {...}, values: [[t, "v"], ...]}]}}` shape.

**Why:** these are the two PromQL forms Grafana uses for the basic "show metric over time, grouped" pattern. Anything else returns 400 — explicit subset, no surprises.

### Decision 7: Feature flags default OFF
**Choice:** every shim has its own enable flag (`crashler.compat.tempo.enabled` etc.). Default is false. When disabled, the routes return 404 (not registered).

**Why:** operators who don't use Grafana don't get a third surface area to maintain. Shims are opt-in.

### Decision 8: Version pinning is documented but not negotiated
**Choice:** each shim's spec names the upstream version it pins to (Tempo 2.x, Loki 2.9.x, Prometheus 2.x). The shim does not negotiate a version with the client; if the client expects a newer feature, they get 400 (for unsupported syntax) or empty data (for unsupported response fields).

**Why:** Grafana's data sources tolerate response-field gaps gracefully (they ignore unknown / missing keys). Pinning to a known-good version means we maintain one shape per shim, not a matrix.

## Risks / Trade-offs

- **Risk: operators expect 100% compatibility once they see the prefix.** → Mitigation: every shim spec opens with a "what we do NOT preserve" list. The 400 responses for unsupported syntax embed the supported-subset message verbatim.

- **Risk: tiny query parsers are easy to get subtly wrong.** → Mitigation: the parsers are deliberately minimal (regex-driven where possible, hand-rolled for the LogQL/PromQL bits we need). Tests assert each accepted form and at least three rejected forms with the right error message.

- **Risk: feature drift as upstream evolves.** Loki 3.x changes the response shape for `query_range`. → Mitigation: pinning is documented in each shim's spec; raising the pin is its own change.

- **Risk: maintenance load if all three shims drift.** → Mitigation: feature flags off by default keep the shims' surface area small in production. CI tests catch regressions.

- **Trade-off: shims duplicate predicate compilation slightly.** Each shim has its own selector parser that maps to the predicate compiler. → Mitigation: predicate compilation is owned by `App\Read\Compute\Predicates\*`; the shim parsers are 1-or-2-page recursive descent that funnels into the same compiler.

- **Trade-off: response-shape duplication.** Each shim has its own JSON shape; shaping is repetitive. → Mitigation: per-shim shapers in `App\Read\Compat\<Vendor>\Response*` keep the duplication contained.

## Migration Plan

- No data migration. No on-disk change.
- Roll-forward: deploy normally with shims disabled. Operators flip the per-shim flag when ready. Each shim's enabling is independent.
- Rollback: revert. Compat routes disappear; canonical `/v1/` continues.
- Communication: README "Grafana compatibility" section explains the flags, the supported subset per shim, and the cross-vendor pattern.

## Open Questions

- Should we ship a bundled Grafana data source plugin (`grafana-crashler-datasource`) that consumes `/v1/` natively, eventually displacing the need for shims? Probably yes, longer term. This change is the bridge.
- Should the shims accept Tempo's `X-Scope-OrgID` / Loki's `X-Scope-OrgID` headers as a fallback tenant signal? Out of scope; Bearer-token tenancy is the single source of truth.
- How do we expose shim health? `/compat/<vendor>/api/echo`-equivalents per shim, returning a tiny JSON `{status: "ok"}`. Already in the Tempo shim; mirrored in the others as `health` endpoints.
