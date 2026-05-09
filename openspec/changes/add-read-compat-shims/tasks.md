## 1. Configuration

- [x] 1.1 Per-shim feature flags `crashler.compat.tempo.enabled` / `crashler.compat.loki.enabled` / `crashler.compat.prometheus.enabled` wired to env vars (defaults: false). Added to `config/services.yaml`
- [~] 1.2 [REPLACED] No conditional route loader — controllers self-check the flag at request time and return 404 when disabled. Equivalent operationally; less plumbing

## 2. Shared shim plumbing

- [~] 2.1 [DEFERRED] `AbstractShimController` — v1 ships only one endpoint per shim, so the shared abstract is overkill; deferred until search/query_range land
- [~] 2.2 [DEFERRED] Shared response shaper — same reason
- [~] 2.3 [DEFERRED] Per-vendor error envelope helper — each controller emits its own error shape inline; tiny code, not yet worth abstracting

## 3. Tempo shim

- [x] 3.1 Added `App\Read\Compat\Tempo\EchoController` returning `200 echo` for `/compat/tempo/api/echo` (Grafana Tempo data source's connection-test endpoint)
- [~] 3.2 [DEFERRED] `SearchController` — Tempo search (with `tags`, `service.name`, `min/maxDuration`) is the next-priority follow-up
- [~] 3.3 [DEFERRED] `TraceByIdController` — would delegate to existing `ReadTraceController` with `Accept: application/otlp+json` forced
- [~] 3.4 [DEFERRED] `TempoLogQLParser` — needed by SearchController
- [x] 3.5 Tests: echo returns 404 when disabled; manual constructor test for the enabled-mode `echo` body

## 4. Loki shim

- [x] 4.1 Added `App\Read\Compat\Loki\LabelsController` returning the closed-list labels response Grafana's Loki data source uses for label browser population
- [~] 4.2 [DEFERRED] `LabelValuesController` — partition scan for distinct values; needs the partition pruner integration
- [~] 4.3 [DEFERRED] `QueryRangeController` and `LogQLSubsetParser` — biggest piece, follow-up
- [x] 4.4 Tests: labels returns 404 when disabled; manual constructor test for enabled-mode body

## 5. Prometheus shim

- [x] 5.1 Added `App\Read\Compat\Prometheus\LabelsController` returning the closed-list labels response Grafana's Prometheus data source uses for label browser population
- [~] 5.2 [DEFERRED] `LabelValuesController` — same as Loki's
- [~] 5.3 [DEFERRED] `QueryRangeController` and `PromQLSubsetParser` — needs the aggregation primitives that ship in `add-read-aggregations`; follow-up
- [x] 5.4 Tests: labels returns 404 when disabled; manual constructor test for enabled-mode body

## 6. Routing

- [x] 6.1 Routes registered via per-controller `#[Route]` attributes (one per controller, conditionally short-circuited by the feature flag). No `config/routes/compat.yaml` needed
- [x] 6.2 Verified via `bin/console debug:router` — three compat routes appear, all return 404 when their flag is false (test-env default)

## 7. Functional / integration tests

- [x] 7.1 `CompatShimsTest`: 7 tests covering disabled-mode 404 (3 endpoints), bearer-required 401 (firewall pattern extended to `^/(v1|compat)/`), enabled-mode bodies (Tempo echo, Loki labels list, Prom labels list)
- [~] 7.2 [DEFERRED] Cross-tenancy test — the search/query_range endpoints aren't shipped, so cross-tenant data isolation isn't testable yet on the compat surface
- [x] 7.3 OpenAPI exclusion verified: the OpenAPI lint command's path scope excludes `/compat/`, so adding shims doesn't trigger documentation lint failures
- [x] 7.4 /v1/ stability: full suite (699/699 green) passes with shim flags toggled OFF

## 8. Documentation

- [~] 8.1 [DEFERRED] README "Grafana compatibility" section — deferred until at least one shim's search/query_range endpoint ships; right now operators flip a flag and get a connection-test response and label browser, not search
- [~] 8.2 [DEFERRED] `docs/grafana-datasources.example.yaml` — same reason
- [~] 8.3 [DEFERRED] Per-shim upstream version pinning in spec — declared in the spec text already; README pointer deferred with 8.1
