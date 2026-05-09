# read-api-docs Specification

## Purpose
TBD - created by archiving change add-read-docs-roundup. Update Purpose after archive.
## Requirements
### Requirement: README documents every shipped read-API surface

The project README SHALL contain documentation subsections for every read-API affordance shipped in the 2026-05-09 batch (post-search, rowgroup-pushdown, multi-attribute-filters, api-spec-examples, aggregations, compat-shims). Each subsection SHALL be located under the existing "Reading data" section and SHALL include at least one worked curl example.

The README's content for each subsection SHALL terminate with a pointer at the relevant `openspec/specs/<capability>/spec.md` so the spec remains the source of truth and any future doc-vs-spec drift can be cross-checked.

#### Scenario: README has an Aggregations subsection
- **WHEN** an operator reads the README's "Reading data" section
- **THEN** there is a subsection titled "Aggregations" (or equivalent) documenting `/v1/<signal>/aggregate`
- **AND** the subsection contains at least one worked curl example for `function=count` with `groupBy=<column>`
- **AND** the subsection names the cardinality cap (`crashler.read.aggregate.max_groups`, default 200)

#### Scenario: README has a Grafana compatibility subsection
- **WHEN** an operator reads the README's "Reading data" section
- **THEN** there is a subsection titled "Grafana compatibility" (or equivalent) documenting the `/compat/<vendor>/` paths
- **AND** the subsection lists the per-shim feature flags (`CRASHLER_COMPAT_TEMPO_ENABLED`, etc., default false)
- **AND** the subsection lists the upstream version pins (Tempo 2.x, Loki 2.9.x, Prometheus 2.x)
- **AND** the subsection links to `docs/grafana-datasources.example.yaml`

#### Scenario: README has an Examples on the spec subsection
- **WHEN** an operator reads the README's "Reading data" section
- **THEN** there is a subsection (titled "Examples on the spec" or equivalent) explaining that `/docs` Swagger UI auto-fills "Try it" forms with the parameter examples declared on each Resource
- **AND** the subsection points at `bin/console app:openapi:lint-examples` for contributors

### Requirement: Project carries a Grafana datasource example

The repository SHALL ship a `docs/grafana-datasources.example.yaml` provisioning snippet showing how to point Grafana data sources at the Crashler compat-shim paths. The file SHALL include entries for at least Tempo, Loki, and Prometheus.

#### Scenario: Grafana example covers the three shipped shims
- **WHEN** an operator opens `docs/grafana-datasources.example.yaml`
- **THEN** the file contains data-source entries for Tempo (URL ending in `/compat/tempo`), Loki (URL ending in `/compat/loki`), and Prometheus (URL ending in `/compat/prom`)

### Requirement: Project carries a contributor guide

The repository SHALL ship a `CONTRIBUTING.md` at the repo root covering at minimum: the OpenSpec proposal/apply/archive workflow, the OpenAPI parameter-examples rule for new read-API endpoints, the lint command, and the PHPUnit invocation.

The contributor guide SHALL be minimal — no comprehensive code-style policy, no exhaustive git-workflow documentation. The bar is "what does a contributor opening their first PR most need to know?"

#### Scenario: CONTRIBUTING.md exists and names the OpenAPI examples rule
- **WHEN** a contributor reads `CONTRIBUTING.md`
- **THEN** the file documents the rule: every new read-API endpoint declared via API Platform must declare a parameter-level `example` and pass `bin/console app:openapi:lint-examples`

