## Why

Five of the six 2026-05-09 read-API changes deferred their README and contributor-doc additions in favour of shipping working code first. That made sense for each change in isolation — adding the docs alongside ships the docs as a side-quest, and the operator-facing surface was small enough to discover via the OpenAPI spec and curl.

That deferral is past its useful expiry now. The aggregate endpoint, the compat shims, and the OpenAPI examples rule are all live; operators landing on the project today have no doc trail. The README's "Reading data" section calls out the GET search endpoints in detail but says nothing about aggregate, the Grafana compat layer, or the lint command. CONTRIBUTING.md doesn't exist yet, and adding it via this change is an opportunity to document the OpenAPI examples rule for future contributors.

This change closes the documentation gaps in one focused pass. No code, no spec deltas. Pure markdown additions.

## What Changes

- README "Aggregations" subsection (under "Reading data"): documents `/v1/<signal>/aggregate` with worked count/sum/avg examples, the `groupBy` allow-list per signal, the cardinality cap, the per-row response shape, and the explicit "v1 ships count/sum/avg/min/max only" scoping with pointers to the percentiles follow-up.
- README "Grafana compatibility" subsection: documents the per-shim feature flags, the connection-test endpoints (`/compat/tempo/api/echo`, `/compat/loki/api/v1/labels`, `/compat/prom/api/v1/labels`), the explicit non-preservations per shim, and the upstream version pins (Tempo 2.x / Loki 2.9.x / Prometheus 2.x).
- README "Examples on the spec" subsection: points at `/docs` Swagger UI and the per-parameter `example` autofill behaviour; references `bin/console app:openapi:lint-examples` for contributors.
- New `docs/grafana-datasources.example.yaml`: Grafana provisioning snippet showing how to point Tempo / Loki / Prometheus data sources at the Crashler shim paths.
- New `CONTRIBUTING.md`: minimal contributor guide focused on the load-bearing rules — every new read-API endpoint declared via API Platform must carry a parameter-level `example`, the lint command must pass on every PR, plus standard pointers to the OpenSpec workflow and PHPUnit invocation.

## Capabilities

### New Capabilities

(none — pure documentation)

### Modified Capabilities

(none)

## Impact

- New code: zero.
- New docs: ~150 lines in README, ~50 lines in `docs/grafana-datasources.example.yaml`, ~80 lines in `CONTRIBUTING.md`.
- No behavioural change. No spec deltas.
- Risk: doc drift over time as the underlying behaviour evolves. → Mitigation: each subsection ties back to a concrete spec capability so a future doc-vs-spec check is a simple cross-reference. The contributor guide explicitly directs new endpoints through the OpenSpec workflow, which keeps spec and docs co-evolving.
