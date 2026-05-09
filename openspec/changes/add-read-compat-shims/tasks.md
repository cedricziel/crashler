## 1. Configuration

- [ ] 1.1 Add per-shim feature flag parameters: `crashler.compat.tempo.enabled`, `crashler.compat.loki.enabled`, `crashler.compat.prometheus.enabled`. Defaults: false. Wire env vars `CRASHLER_COMPAT_TEMPO_ENABLED`, `CRASHLER_COMPAT_LOKI_ENABLED`, `CRASHLER_COMPAT_PROMETHEUS_ENABLED`
- [ ] 1.2 Add a Symfony route loader that conditionally registers each shim's controllers based on the flag

## 2. Shared shim plumbing

- [ ] 2.1 Add `App\Read\Compat\AbstractShimController` carrying the auth/tenancy lookup and time-window resolution helpers
- [ ] 2.2 Add `App\Read\Compat\ResponseShaper` interface and per-shim implementations
- [ ] 2.3 Add `App\Read\Compat\ErrorEnvelope` helper that emits the upstream-shaped error body for each vendor (Tempo: `{status: "error", error: "..."}`; Loki/Prometheus: `{status: "error", errorType: "...", error: "..."}`)

## 3. Tempo shim

- [ ] 3.1 Add `App\Read\Compat\Tempo\EchoController` returning `200 echo` for `/compat/tempo/api/echo`
- [ ] 3.2 Add `App\Read\Compat\Tempo\SearchController`: parse `tags`, `service.name`, `minDuration`, `maxDuration`, `start`, `end`, `limit`; compile to predicates; reuse `ParquetScanner`; group rows by `traceId`; emit Tempo's `{traces: [...]}` shape with root-span info
- [ ] 3.3 Add `App\Read\Compat\Tempo\TraceByIdController`: thin delegation to `ReadTraceController` with `Accept: application/otlp+json` forced
- [ ] 3.4 Add `App\Read\Compat\Tempo\TempoLogQLParser` (small, regex-driven `tags=` parser)
- [ ] 3.5 Tests: echo, search with tags, search with service.name, search with min/max duration, trace-by-id, TraceQL rejected, oversize tag count rejected

## 4. Loki shim

- [ ] 4.1 Add `App\Read\Compat\Loki\LabelsController` (closed list) and `App\Read\Compat\Loki\LabelValuesController` (partition scan for distinct values)
- [ ] 4.2 Add `App\Read\Compat\Loki\QueryRangeController`: parse `query` via `LogQLSubsetParser` (selector + optional line filter); compile to predicates; scan; group rows into Loki streams
- [ ] 4.3 Add `App\Read\Compat\Loki\LogQLSubsetParser` — selector + line filter only; reject regex selectors, range vectors, aggregations, label filters, format expressions
- [ ] 4.4 Tests: labels, label values per supported label, query_range with selector, query_range with line filter, query_range backward direction, regex rejected, aggregation rejected, range vector rejected

## 5. Prometheus shim

- [ ] 5.1 Add `App\Read\Compat\Prometheus\LabelsController` and `LabelValuesController` (closed list, partition scan)
- [ ] 5.2 Add `App\Read\Compat\Prometheus\QueryRangeController`: parse `query` via `PromQLSubsetParser` (raw selector | `count_over_time(<sel>[<range>])` | `sum by (<labels>) (<sel>)`); delegate to `add-read-aggregations` primitives where applicable
- [ ] 5.3 Add `App\Read\Compat\Prometheus\PromQLSubsetParser` — explicit allow-list of forms; reject everything else with informative error
- [ ] 5.4 Tests: labels, label values, raw-selector matrix, count_over_time matrix, sum-by matrix, histogram_quantile rejected, comparison operator rejected

## 6. Routing

- [ ] 6.1 Add `config/routes/compat.yaml` (or equivalent) with route definitions guarded by per-shim env-derived feature flags
- [ ] 6.2 Verify that toggling a flag and clearing the cache produces 404 vs. 200 on the corresponding endpoints

## 7. Functional / integration tests

- [ ] 7.1 Smoke test: stand up a temp Grafana data source pointing at the test Crashler, run a "Test connection" — Tempo / Loki / Prometheus all green when their flag is on
- [ ] 7.2 Cross-tenancy test: shim endpoints with bearer for tenant A do not surface data from tenant B
- [ ] 7.3 OpenAPI exclusion test: assert no shim path appears under `/docs.jsonopenapi` `paths`
- [ ] 7.4 /v1/ stability test: toggle every flag combination and verify no /v1/ test changes outcome

## 8. Documentation

- [ ] 8.1 Add a "Grafana compatibility" section to the project README explaining the shim paths, the per-shim flags, and the explicit non-preservations
- [ ] 8.2 Include a snippet `docs/grafana-datasources.example.yaml` showing how a Grafana provisioning file points at the shims
- [ ] 8.3 For each shim, link to the upstream API documentation version we pin to, so operators can cross-reference behaviour
