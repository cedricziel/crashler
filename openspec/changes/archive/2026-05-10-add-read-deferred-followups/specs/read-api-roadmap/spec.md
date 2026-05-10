## ADDED Requirements

### Requirement: Read-API deferred-work tracker

The system SHALL maintain a single tracker change at `openspec/changes/add-read-deferred-followups/` (or its archived successor) enumerating every `[~]`-marked deferred task across the read-API archives. Each tracker entry SHALL reference the originating archived change and the original task ID so the rationale for the deferral remains discoverable.

The tracker SHALL group entries into themes that mirror the original architectural seams (per-capability follow-ups for aggregations, compat-shims, OpenAPI examples) plus cross-cutting themes (test-coverage roundup, docs roundup) where activities span multiple capabilities.

When a theme is picked up for implementation, the workflow SHALL be:

1. Run `/opsx:propose <theme-name>` to scaffold a new change directory with proposal/design/tasks.
2. Copy the theme's tasks from this tracker into the new change's `tasks.md` as the starting point.
3. Mark each absorbed item in this tracker with `[x] [PROMOTED to <theme>]` so the historical trail is preserved.
4. When every theme has been promoted or explicitly dropped, archive the tracker.

The tracker SHALL NOT be load-bearing — its absence at any future point is acceptable. Its purpose is single-touchpoint discoverability for "what didn't ship in the 2026-05-09 read-API batch and why".

#### Scenario: Tracker enumerates the themes from the 2026-05-09 batch
- **WHEN** an operator opens the tracker's `tasks.md`
- **THEN** the tasks are grouped under at least: `add-read-aggregations-percentiles`, `add-read-compat-shims-querying`, `add-read-openapi-bodies`, `add-read-test-coverage-roundup`, `add-read-docs-roundup`

#### Scenario: Each tracker entry is back-referenced
- **WHEN** the tracker lists a deferred task
- **THEN** the task carries a "Reference: <archived-change> <task-id>" pointer naming the originating change and the original task number

#### Scenario: Tracker is archive-eligible after themes are picked up
- **WHEN** every theme listed in the tracker has been promoted to its own change OR explicitly dropped
- **THEN** the tracker can be archived with `/opsx:archive add-read-deferred-followups`
