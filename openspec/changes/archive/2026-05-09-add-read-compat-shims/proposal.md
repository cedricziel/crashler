## Why

The Crashler read API is OTLP-faithful and self-consistent across signals (Hydra/HAL/JSON/JSON:API; cross-signal `_links` to traces / spans / metrics). It is *not* directly consumable by the dominant observability tooling: Grafana's Tempo data source talks to a Tempo HTTP API, its Loki source to a Loki HTTP API, its Prometheus source to a Prometheus HTTP API.

Operators who have invested in those dashboards do not want to rebuild their UI to consume Hydra, even if Hydra is the better long-term choice. The pragmatic answer is a thin compatibility-shim layer: a small, deliberately-scoped set of additional endpoints that translate Tempo / Loki / Prometheus query shapes into Crashler reads, and translate the Crashler responses back into the shapes those data sources expect.

Each shim is read-only, additive, scoped to the minimum surface that drives a Grafana data source's "Test connection" + "Run a basic query", and explicit about which semantics it does NOT preserve. The shims are not a complete reimplementation of the upstream APIs — they are a "Grafana data source works" affordance.

This change ships the umbrella capability and three shim subsets:
- **Tempo shim** (traces): `GET /compat/tempo/api/echo`, `GET /compat/tempo/api/search`, `GET /compat/tempo/api/traces/{traceId}`.
- **Loki shim** (logs): `GET /compat/loki/api/v1/labels`, `GET /compat/loki/api/v1/label/{name}/values`, `GET /compat/loki/api/v1/query_range`.
- **Prometheus shim** (metrics, deliberately minimal): `GET /compat/prom/api/v1/labels`, `GET /compat/prom/api/v1/label/{name}/values`, `GET /compat/prom/api/v1/query_range` for a fixed-form `count_over_time` / `sum by (..)` workload.

The shims sit alongside the canonical `/v1/` endpoints and do not displace them.

## What Changes

- New `compat-shims` capability documenting the umbrella contract: shims sit under `/compat/<vendor>/` paths, share the same Bearer-token authentication, the same tenant scoping, and the same execution-timeout governance, and explicitly list what they do NOT preserve from the upstream APIs.
- New `compat-tempo` capability for the Tempo subset.
- New `compat-loki` capability for the Loki subset.
- New `compat-prometheus` capability for the Prometheus subset.
- New PHP code:
  - `App\Read\Compat\Tempo\*` — Symfony controllers for the three Tempo endpoints. Each delegates to the existing scanner / state-providers and shapes the response per the Tempo contract.
  - `App\Read\Compat\Loki\*` — same shape for Loki, including a tiny LogQL parser limited to label-equals selectors `{label="value"}` plus optional `|= "substring"` line filter.
  - `App\Read\Compat\Prometheus\*` — same shape for Prometheus, with a tiny PromQL parser that handles only the `count_over_time(<selector>[<duration>])` and `sum by (<labels>) (<selector>)` forms used by Grafana's "Run query" default.
- Smoke-test fixtures: a small Grafana data source configuration in `docs/grafana-datasources.example.yaml` (or a README link to one) that points at the compat endpoints.
- README: a "Grafana compatibility" section pointing at the shim paths and listing the explicit non-preservations (no LogQL aggregations beyond what's documented; no PromQL beyond what's documented; no Tempo `streamingFlags`).
- Tests: per-shim functional tests, plus a "compat surface is bounded" test that asserts no other paths under `/compat/` exist.

## Capabilities

### New Capabilities

- `compat-shims`: umbrella contract — auth, tenant, timeout, version-pinning, what is and isn't preserved.
- `compat-tempo`: Tempo subset.
- `compat-loki`: Loki subset.
- `compat-prometheus`: Prometheus subset.

### Modified Capabilities

- `read-api`: a one-paragraph reference pointing at the new compat capabilities so the read-API capability stays an index of all read surfaces.

## Impact

- New code: ~600 lines across the three shim layers (controllers + tiny query parsers + response shapers).
- Tests: ~15 functional tests covering the documented shapes.
- Config: per-shim feature flags (default off): `crashler.compat.tempo.enabled`, `crashler.compat.loki.enabled`, `crashler.compat.prometheus.enabled`. Operators opt in.
- No backward-incompatible behaviour. The canonical `/v1/` endpoints are unchanged. Shim endpoints sit at distinct paths.
- No new dependencies. Nor any reuse of the OTLP write code.
- Operational risk: the shims invite people to expect 100% upstream-compat. → Mitigation: explicit "what we don't preserve" documentation per shim; hard 400 on any unsupported PromQL/LogQL syntax with a message naming the supported subset.
- Versioning: each shim's response shape MUST follow the upstream version we pin to (Tempo 2.x, Loki 2.9.x, Prometheus 2.x) and the version is documented in the shim's spec.
