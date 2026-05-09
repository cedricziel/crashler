## 1. README "Aggregations" subsection

- [x] 1.1 Inserted "Aggregations" subsection under README's "Reading data" section
- [x] 1.2 Three worked curl examples shipped (count, count+groupBy=service, sum+column=severityNumber+groupBy=service)
- [x] 1.3 Supported functions listed with forward-pointer to percentiles follow-up
- [x] 1.4 Per-signal `groupBy` allow-lists documented (logs/traces/metrics)
- [x] 1.5 Cardinality cap named with env override
- [x] 1.6 Reference to `openspec/specs/read-aggregations/spec.md` shipped

## 2. README "Grafana compatibility" subsection

- [x] 2.1 "Grafana compatibility" subsection inserted
- [x] 2.2 Per-shim feature flags + env vars + defaults documented
- [x] 2.3 V1 endpoints per shim listed with explicit deferrals
- [x] 2.4 Upstream version pins listed (Tempo 2.x / Loki 2.9.x / Prometheus 2.x)
- [x] 2.5 Links to `docs/grafana-datasources.example.yaml` and `openspec/specs/compat-shims/spec.md` shipped

## 3. README "Examples on the spec" subsection

- [x] 3.1 "Examples on the spec" subsection inserted
- [x] 3.2 `/docs` and `/docs.jsonopenapi` referenced
- [x] 3.3 `bin/console app:openapi:lint-examples` named in the contributor guidance

## 4. docs/grafana-datasources.example.yaml

- [x] 4.1 Created `docs/grafana-datasources.example.yaml`
- [x] 4.2 Tempo entry shipped with bearer auth and proxy access mode
- [x] 4.3 Loki entry shipped
- [x] 4.4 Prometheus entry shipped
- [x] 4.5 Header comment names the per-shim feature flags and substitution placeholders

## 5. CONTRIBUTING.md

- [x] 5.1 Created `CONTRIBUTING.md` at the repo root
- [x] 5.2 "Workflow" section documents the OpenSpec stages and directory layout
- [x] 5.3 "OpenAPI examples rule" section ships with the example code block, lint command, and rationale-pointer
- [x] 5.4 "Tests" section documents `vendor/bin/phpunit` + `composer test` plus per-suite invocations
- [x] 5.5 "Conventions" section covers `composer require`, read/write split, and per-signal patterns

## 6. Verification

- [x] 6.1 Markdown reads cleanly: code blocks closed, internal links resolve, headings nest under existing structure
- [x] 6.2 Test suite: 710/710 green (unchanged from pre-change state — no production code touched)
- [x] 6.3 Umbrella `add-read-deferred-followups` updated — all §5.1–§5.5 marked `[PROMOTED to add-read-docs-roundup §N]`
