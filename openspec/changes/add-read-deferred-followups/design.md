## Context

The 2026-05-09 read-API batch shipped six changes (post-search, rowgroup-pushdown, multi-attribute-filters, api-spec-examples, aggregations, compat-shims) each with deliberate scope cuts captured under `[~]` markers in their tasks.md. Across the six archives there are roughly 30 deferred items.

Without an umbrella tracker, those items disappear into the per-change archive trees and surface only when a developer happens to read the originating change. The umbrella exists so a future `/opsx:propose` can scan the catalogue, pick a theme, and produce a focused change with the right scope.

Stakeholders: future implementers (the people picking up themes) and reviewers (judging whether a new change should incorporate or reuse a deferred item). The umbrella is for them, not for end users.

## Goals / Non-Goals

**Goals:**
- Catalogue every deferred item from the six 2026-05-09 archives, grouped thematically.
- Each entry points back to the archived change and the original task ID so the rationale is one click away.
- Themes are independent so they can be picked up in any order.

**Non-Goals:**
- Prescribing a roadmap. The themes are options, not commitments.
- Adding spec deltas. Each theme will produce its own deltas when implemented.
- Resurrecting cuts that turned out to be the right call. Some `[~]` items may stay deferred forever; that's fine.

## Decisions

### Decision 1: One umbrella change instead of five thematic proposals
**Choice:** a single `add-read-deferred-followups` change that lists every deferred item under five thematic groupings.

**Alternative considered:** five separate proposals (one per theme, each with its own proposal/design/tasks).

**Why:** five proposals is heavier-weight than the value at this stage — none of the themes are "ready to implement now". The umbrella is a backlog item, not a commitment to ship anything. When a theme is actually prioritized, splitting it out is a `/opsx:propose <theme>` away.

### Decision 2: Themes follow the original change boundaries where possible
**Choice:** the five themes mirror the original change boundaries (`add-read-aggregations-*`, `add-read-compat-shims-*`, etc.) so the work to pick them up is mostly "extend the existing capability spec" rather than "rethink the architecture".

**Why:** the original changes shipped clean architectural seams. Building atop those seams is much easier than re-litigating them. The exception is `add-read-test-coverage-roundup` and `add-read-docs-roundup`, which span themes and benefit from being grouped by activity (test-writing vs. doc-writing) rather than by capability.

### Decision 3: This change ships ZERO code or specs
**Choice:** proposal + design + tasks only. No new spec deltas. The follow-up themes will produce deltas when they're implemented.

**Why:** the umbrella is documentation about future work, not a contract. Mixing tracker entries into living specs would pollute them with "TBD" sections that go stale.

## Risks / Trade-offs

- **Risk: themes drift apart over time** as new features land and reshape priorities. → Mitigation: when a theme is picked up, its proposal explicitly states which deferred items it absorbs and which (if any) it punts further. The umbrella is allowed to outlive its usefulness; it's not load-bearing.

- **Risk: deferred items get re-discovered organically rather than via this list.** → Mitigation: this is the intended outcome. The umbrella provides a single search target; if a developer finds an item via a different path, they can still cross-reference here. The goal is visibility, not gating.

- **Trade-off: enumeration overhead.** Maintaining the catalog as the source-of-truth lists every `[~]` task by ID. If a theme ships, the list needs pruning. → Mitigation: this is light maintenance; pruning happens during the theme's own implementation pass.

## Migration Plan

- No deployment. No data migration. No spec update.
- Roll-forward: the proposal, design, and tasks files exist in `openspec/changes/add-read-deferred-followups/`. They are referenced from CHANGELOG (when one is added) or from the per-theme follow-up changes that absorb them.
- Roll-back: delete the directory. No fallout.

## Open Questions

- Should themes be sized to fit individual changes, or are some too big and need further splitting? → defer to the implementation pass; the size only matters when someone is about to ship.
- Should this umbrella be archived in its current form (closing the historical tracker) or kept open as a living index? → keep it active until at least one theme has been picked up, then revisit.
