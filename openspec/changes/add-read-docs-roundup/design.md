## Context

The 2026-05-09 read-API batch shipped six features (post-search, rowgroup-pushdown, multi-attribute-filters, api-spec-examples, aggregations, compat-shims) and deferred their README expansions five times, on the rationale that "the README has working examples for the GET search; the rest is discoverable via OpenAPI". That rationale was correct under each change's time budget but cumulatively leaves the README out of date.

Stakeholders:
- Operators landing on the project (typically reading the README first, the OpenAPI spec second).
- Contributors writing new endpoints (looking for "what's the rule for this kind of endpoint?").
- Internal tools and AI assistants consuming the project's text surface for context.

The cleanest fix is to write the missing subsections in one pass. The content already exists — in the per-change archived specs and design docs — so this is largely a translation exercise.

## Goals / Non-Goals

**Goals:**
- README's "Reading data" section becomes a complete index of every read-API affordance shipped in the 2026-05-09 batch.
- Each new subsection terminates with a "see also" pointer at the relevant spec capability so the spec stays the source of truth.
- A `CONTRIBUTING.md` exists at the repo root with the load-bearing rules new contributors most need: the OpenSpec workflow, the OpenAPI examples rule, the test invocation.
- A `docs/grafana-datasources.example.yaml` exists so operators with Grafana can wire up the compat shims with a copy-paste.

**Non-Goals:**
- A full Grafana setup tutorial. The example YAML is enough; deeper integration is the operator's concern.
- A redesign of the README structure. Keep insertions in the existing "Reading data" section, preserving the existing flow.
- Documenting features that haven't shipped (percentiles, full LogQL/PromQL, response-body OpenAPI examples). Those track to their own follow-ups and will land with their own docs.

## Decisions

### Decision 1: README subsections live under "Reading data"
**Choice:** the three new README subsections (Aggregations, Grafana compatibility, Examples on the spec) all sit under the existing "Reading data" section rather than getting their own top-level sections.

**Why:** preserves the existing reader flow. Someone reading from the top hits "Reading data" → "Endpoints" → "Filters" → "Wire formats" → "Examples" → … new content here → "Operator/debug recipes". The new subsections are continuation, not interruption.

### Decision 2: Grafana datasource example as a separate file
**Choice:** `docs/grafana-datasources.example.yaml` is a separate file rather than an inline snippet in the README.

**Why:** Grafana provisioning files are copy-paste artifacts. Keeping the YAML in its own file means operators can `wget` it directly without having to extract from a markdown code block. The README's "Grafana compatibility" subsection links to it.

### Decision 3: CONTRIBUTING.md is minimal-by-design
**Choice:** the contributor guide ships only the rules new contributors must know to be productive: OpenSpec workflow, OpenAPI examples rule + lint command, PHPUnit invocation. It does NOT document our git workflow, code style, or commit conventions in detail.

**Why:** a long CONTRIBUTING is a CONTRIBUTING nobody reads. The minimum-viable doc points at the existing spec system + the existing test command. Style/code-review nuance lives in PR comments where it can be context-specific.

### Decision 4: No screenshots, no diagrams
**Choice:** prose + code blocks only. No Swagger UI screenshots, no architecture diagrams.

**Why:** screenshots rot quickly (Swagger UI's appearance changes between AP4 versions). Diagrams add maintenance load. The prose is enough for the audience this change targets.

## Risks / Trade-offs

- **Risk: doc drift.** The README adds detail that will outpace the underlying spec if features evolve. → Mitigation: each subsection ends with a "see `openspec/specs/<capability>/spec.md`" reference. When doc and spec disagree, the spec wins.

- **Trade-off: line-count budget.** ~280 lines of new content is non-trivial. → Mitigation: the content earns its place by closing five separate documentation deferrals at once. Splitting into five separate doc PRs would be more overhead than value.

- **Trade-off: CONTRIBUTING.md is a new file with its own maintenance burden.** → Mitigation: minimal-by-design (≤80 lines). When a future change extends the contributor surface, that change owns the corresponding CONTRIBUTING update.

## Migration Plan

- No deployment. No data migration.
- Roll-forward: docs land in tree.
- Roll-back: revert. No fallout.

## Open Questions

- Should the README's existing "Examples" subsection be merged into the new "Examples on the spec" subsection? → no, they cover different things: the existing one shows curl recipes for end-users, the new one points at Swagger UI for API exploration. Keep them distinct.
- Should `docs/grafana-datasources.example.yaml` use `localhost` or a placeholder URL? → placeholder (`https://crashler.example.com`) so operators don't accidentally provision against the wrong host. The README sentence around the link explicitly tells them to substitute.
