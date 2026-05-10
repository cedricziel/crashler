## Context

The project's `composer.json` carries production deps for a Symfony 8.0 / API Platform 4.3 / Doctrine 3.6 stack pinned to PHP 8.5. Quality tools want different versions of the same fundamental libraries:

- PHPStan 1.x and 2.x both bundle `nikic/php-parser`. PHPStan's PHAR ships a self-contained version, but `phpstan/phpstan` as a library wants `nikic/php-parser` ^4 or ^5; API Platform pulls `^5`. Workable, but tight.
- PHP-CS-Fixer pins `symfony/console` to its supported range (currently ^5.4 || ^6.0 || ^7.0). It does not yet support Symfony 8 in stable releases — adding it as a top-level dev-dep would force a downgrade or block updates.
- Rector pulls a long-tail of phpstan-* packages with their own version constraints. Including it top-level guarantees future composer-update friction.

Plus, every additional dev-dep grows the autoloader and Flex recipe surface, raising boot time for tests and the dev cache by a measurable amount.

The "isolated tools" pattern (one `composer.json` per tool under `tools/<tool>/`) is the established PHP solution. Each tool lives in its own dependency universe; the main app sees nothing. Tool binaries are invoked via `tools/<tool>/vendor/bin/<binary>`. The pattern is popularised by `bamarni/composer-bin-plugin` but doesn't require it — plain directories with their own composer.json files work fine and keep the mental model obvious.

## Goals / Non-Goals

**Goals:**

- Run static analysis (PHPStan), code-style enforcement (PHP-CS-Fixer), and refactor lints (Rector) without polluting the main `composer.json`.
- Block commits that fail style or static analysis, so problems never reach `main`.
- Keep the pre-commit experience fast (target: < 10s on a typical change set; < 2s on a single-file commit).
- Make the same checks run identically in CI (so a green hook locally guarantees a green CI build for these checks).
- Zero ceremony for cloners: `composer install` followed by an obvious follow-up gets them set up.

**Non-Goals:**

- Replacing `composer test` or running PHPUnit on every commit. Tests are the CI gate; the hook is for fast feedback only.
- Auto-installing tool vendors on commit. Operators run `composer tools:install` once after clone; the hook fails loudly if a tool's `vendor/` is missing rather than installing implicitly (which would surprise on slow networks).
- Replacing existing CI test stages. `composer quality` becomes a sibling of `composer test`, not a replacement.
- Coverage tooling, mutation testing, dependency-vulnerability scanning. Each is its own concern; this change establishes the *pattern* and ships three concrete tools, not the entire universe of quality checks.

## Decisions

### Decision 1: Plain directories under `tools/`, no `bamarni/composer-bin-plugin`

**Choice:** create `tools/phpstan/`, `tools/php-cs-fixer/`, `tools/rector/`, each with its own `composer.json`. Operators run `composer install -d tools/<name>` (or the umbrella `composer tools:install`) to populate `tools/<name>/vendor/`.

**Alternative considered:** `bamarni/composer-bin-plugin`. Rejected — adds a top-level plugin dep, ties the workflow to a third-party convention, and duplicates what `composer -d` already does cleanly.

**Why:** the pattern is transparent. Each `tools/<name>/composer.json` is a self-contained mini-project; nothing magical happens. New contributors can read it without needing to know what bamarni's plugin does.

### Decision 2: Tracked `.githooks/`, opt-in via `core.hooksPath`

**Choice:** ship the hook as `.githooks/pre-commit`, marked executable. A post-install composer script prints a one-liner the user can run (`git config core.hooksPath .githooks`) once per clone. Don't auto-set the config — that's surprising, and breaks workflows where developers route hooks through their own machinery (Husky, lefthook, GitButler).

**Alternative considered:** `captainhook/captainhook` or `bruli/php-git-hooks`. Rejected — captainhook is featureful but introduces a new mental model and a new config file (`captainhook.json`). For a three-tool setup, a 30-line shell script is clearer and has zero install footprint.

**Alternative considered:** auto-setting `core.hooksPath` from a post-install script. Rejected — silently rewriting a developer's git config is a surprise. The post-install message is loud and explicit.

**Why:** transparency wins. Anyone reading `.githooks/pre-commit` understands exactly what runs.

### Decision 3: PHP-CS-Fixer auto-fixes; PHPStan is check-only

**Choice:** the pre-commit hook runs:

1. PHP-CS-Fixer in `--diff` mode against staged `.php` files; if it changed any, it re-stages them automatically and continues.
2. PHPStan against the whole project; if it returns non-zero, the commit is aborted.
3. (Rector is *not* run pre-commit — refactor rules are too aggressive for a hot path. Rector runs via `composer rector:dry` on demand and `composer rector` when explicitly asked.)

**Alternative considered:** PHPStan on changed files only. Rejected — PHPStan's reasoning depends on the whole project graph; a partial run can hide regressions.

**Alternative considered:** auto-fix with PHPStan baseline updates too. Rejected — automatic baseline drift hides real problems.

**Why:** style is mechanical and uncontroversial; auto-fix is a quality-of-life win. Static analysis is judgement; the human commits should adjudicate.

### Decision 4: Convenience composer scripts at the top level

**Choice:** the main `composer.json` gains scripts that proxy into the tool directories:

```json
"scripts": {
    "tools:install":    "for t in tools/*; do composer install -d \"$t\" --no-interaction; done",
    "tools:update":     "for t in tools/*; do composer update -d \"$t\" --no-interaction; done",
    "phpstan":          "tools/phpstan/vendor/bin/phpstan analyse --memory-limit=1G",
    "cs:check":         "tools/php-cs-fixer/vendor/bin/php-cs-fixer check --diff",
    "cs:fix":           "tools/php-cs-fixer/vendor/bin/php-cs-fixer fix",
    "rector":           "tools/rector/vendor/bin/rector process",
    "rector:dry":       "tools/rector/vendor/bin/rector process --dry-run",
    "quality":          ["@cs:check", "@phpstan"]
}
```

**Why:** developers invoke the *same* commands locally and CI invokes them too. No `make` indirection, no shell-script wrappers, no surprises about which binary runs. `composer quality` is the canonical "is this branch clean?" entry point.

### Decision 5: Configuration files at the project root, not under `tools/<tool>/`

**Choice:** ship `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, and `rector.php` at the project root. Each tool's binary defaults to looking there.

**Alternative considered:** put configs under `tools/<tool>/`. Rejected — configs are project-shape (which directories to scan, what level to enforce, which Symfony version), not tool-shape. They belong at the root with the rest of the project's developer-facing configuration.

**Why:** standard convention; matches what every Symfony project does.

### Decision 6: Initial PHPStan level 6

**Choice:** start at level 6 (configurable up). Level 6 catches the obvious nullable / mixed-return / type-mismatch issues without forcing ironclad-strict generics annotations everywhere. Easy to ratchet up later by editing one line in `phpstan.dist.neon`.

**Alternative considered:** level 9 (max). Rejected — would require a multi-day generics-annotation pass before the first green run, which would make landing this change a much bigger lift.

**Alternative considered:** level 0 or 1. Rejected — too lax to catch anything meaningful.

**Why:** level 6 is the sweet spot the Symfony / Doctrine community has converged on. It catches real bugs without demanding type-system encyclopaedism on every PR.

### Decision 7: PHP-CS-Fixer ruleset = `@Symfony` + `@Symfony:risky`, with project-specific opt-outs

**Choice:** start from the `@Symfony` and `@Symfony:risky` presets and add a small `<rules>` block for the few overrides this project actually wants (e.g., trailing-comma in arrays, single-line throws). The `.php-cs-fixer.dist.php` lives at the repo root and excludes `var/`, `vendor/`, `tools/*/vendor/`, `migrations/`.

**Why:** Symfony preset matches the codebase shape and what every Symfony developer expects. Risky rules are valuable (auto-add `declare(strict_types=1)`, normalise PHPDoc) and the codebase is already mostly conformant.

### Decision 8: Rector config minimal at first

**Choice:** `rector.php` starts with `withPhpSets(php85: true)` and `withSymfonySets(symfonyCodeQuality: true)`. No other rules. Rector runs in `--dry-run` mode under `composer rector:dry` so contributors can see what it *would* change. Apply with `composer rector` when an upgrade is intentional.

**Why:** Rector is powerful and dangerous. Starting minimal means surprises are containable. Rules are easy to add as the team finds patterns worth automating.

### Decision 9: Dependabot tracks every `tools/<tool>/` manifest

**Choice:** ship `.github/dependabot.yml` with one `package-ecosystem: composer` entry per `tools/<tool>/` directory plus an entry for the root. Each entry pins its `directory:` to the tool's path and runs on the same weekly schedule (Mondays UTC). All entries share the `open-pull-requests-limit: 5` cap to bound noise.

**Why:** Dependabot's `composer` ecosystem only scans the directory it's pointed at — it does not walk subdirectories looking for additional `composer.json` files. Without per-tool entries, PHPStan, PHP-CS-Fixer, and Rector versions silently drift. Per-tool entries make the upgrade flow consistent: a security advisory hits the tool, Dependabot opens a PR against `tools/<tool>/composer.lock`, the lockfile is small enough to review at a glance, and merging it changes nothing in the production graph.

**Alternative considered:** scan only the root `composer.json` and trust manual `composer tools:update`. Rejected — manual upgrade discipline always slips. The point of isolating tools is to make their churn invisible to production; Dependabot makes their churn invisible to humans too.

**Convention:** when a future quality tool is added, the contributor SHALL also add the corresponding Dependabot entry. Adding a tool without the entry is treated as an incomplete change in code review. The `.github/dependabot.yml` thus becomes a flat enumeration of every tools/ directory plus the root — a small price for a self-maintaining quality stack.

## Risks / Trade-offs

- **Risk: pre-commit hook slows down trivial commits.** PHPStan on the whole project takes 3–10s depending on cache state. → Mitigation: PHPStan caches incrementally to `var/cache/phpstan/`; second run on the same branch is sub-second. PHP-CS-Fixer on a one-file change is < 1s. Total typical hook time: 2–4s. We accept the worst case.
- **Risk: tools/ directories become out-of-sync between contributors.** One person updates phpstan locally and bumps `tools/phpstan/composer.lock`; another forgets and runs an older version. → Mitigation: each `tools/<tool>/composer.lock` is committed; running `composer tools:install` brings the lockfile to canonical state. CI runs `tools:install` from scratch each build.
- **Risk: pre-commit auto-fix re-stages files the user didn't intend to change.** → Mitigation: the hook prints what was changed before re-staging, so a `git diff --cached` still shows the auto-fixes. Also, PHP-CS-Fixer is deterministic; the same input produces the same output.
- **Risk: a contributor commits without the hook (`--no-verify` or never set `core.hooksPath`).** → Mitigation: CI runs `composer quality` and fails the build. The hook is a fast feedback loop, not the gate.
- **Risk: tool versions drift from CI.** → Mitigation: lockfiles per tool ensure deterministic versions. CI runs `composer install -d tools/<tool>` which respects lockfiles.
- **Trade-off: three tools, three composer.json, three lockfiles.** Slightly more files. → Acceptable. Each is small (typically 10-20 lines of JSON), and the structure is transparent.

## Migration Plan

- **First-time setup per contributor:**
  1. `composer tools:install` — populates `tools/phpstan/vendor`, `tools/php-cs-fixer/vendor`, `tools/rector/vendor`.
  2. `git config core.hooksPath .githooks` — opts in to the pre-commit hook (one-time per clone).
  3. (Optional) `composer quality` — verify the project is currently clean.

- **Existing branches in flight:** rebasing onto `main` after this lands triggers the hook on the first commit. PHP-CS-Fixer auto-fixes are routine; PHPStan errors require attention.

- **CI:** add a `composer quality` step. Failing the step fails the build.

- **Rollback:** delete `tools/`, `.githooks/`, the new composer scripts, and the three root config files. Operators who set `core.hooksPath` reset it manually (`git config --unset core.hooksPath`). No data, no schema, no operational changes.

## Open Questions

- **Should `composer install` automatically run `composer tools:install` via post-install?** Pros: zero manual ceremony for new contributors. Cons: doubles install time on every `composer install`, which is annoying when iterating on production deps. Leaning *no* — keep it explicit.
- **Should the hook run in CI too, or only `composer quality`?** Conceptually the hook is the "fast" version; CI runs the same checks via the composer scripts. Leaning *only the composer scripts* — running the hook in CI implies CI clones might re-stage files, which is wrong.
- **Do we want a `composer cs:fix-all` that runs PHP-CS-Fixer on the whole project (vs. the hook's staged-files-only)?** Yes — `composer cs:fix` already does this (no `--staged-only` flag); the hook explicitly passes the staged file list. Worth documenting clearly in the README.
- **Do we want PHPStan's deprecation rules?** They catch real upgrade-blocker bugs but generate noise. Leaning *enable, with a small baseline*.
