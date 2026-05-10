## Why

The repo has no automated quality gate. Style, static-analysis, and refactor checks rely on manual discipline ("run lint and format before commit") that nobody actually runs. Without a hook that fails fast, problems land on `main` and surface only when CI catches them — or worse, when a reviewer catches them in PR. At the same time, adding tools like PHPStan and PHP-CS-Fixer to the project's main `composer.json` pollutes the production dependency graph and creates real-world conflicts (PHPStan pulls a different `nikic/php-parser` minor than API Platform; PHP-CS-Fixer pulls `symfony/console` ^7 which fights with Symfony 8.0).

This change introduces a developer-facing quality stack that sidesteps both problems: each quality tool gets its own isolated `composer.json` under `tools/<tool>/`, and a tracked `.githooks/pre-commit` hook runs them on staged changes before the commit lands.

## What Changes

- **New `tools/` layout** — one directory per quality tool, each with its own `composer.json` and `vendor/`. Tools install via `composer install -d tools/<tool>` (or a top-level `composer tools:install` script that loops). The main `composer.json` stays untouched: production deps don't see PHPStan / PHP-CS-Fixer / Rector at all.
- **Three initial tools** — PHPStan (static analysis at level 6, with `phpstan/phpstan-symfony` and `phpstan/phpstan-doctrine`), PHP-CS-Fixer (style enforcement using the shipped `.php-cs-fixer.dist.php`), and Rector (config-driven automated refactors at the PHP-85 + Symfony-8 ruleset). Each is invokable via `tools/<tool>/vendor/bin/<binary>` directly, plus convenience composer scripts at the top level (`composer phpstan`, `composer cs:fix`, `composer cs:check`, `composer rector`, `composer rector:dry`).
- **Tracked git hooks** — a new `.githooks/` directory with a `pre-commit` script. The hook is opt-in by default (a one-time `git config core.hooksPath .githooks` per clone, run by `composer install`'s post-install script when on a TTY) so CI / scripted environments are unaffected. The pre-commit script runs PHP-CS-Fixer on staged `.php` files (auto-fix + re-stage), then PHPStan against the project. Runs in under 10 seconds for a typical commit.
- **CI-friendly runner** — a single `composer quality` script that runs `cs:check` + `phpstan` non-interactively. Same checks the hook runs, but in check-only mode so CI fails on diff rather than auto-fixing.
- **Bootstrap commands** — `composer tools:install` (install all tool vendors), `composer tools:update` (refresh), and a clear README "Quality stack" section.
- **Dependabot wired to every tool manifest** — `.github/dependabot.yml` declares one `composer` ecosystem entry per `tools/<tool>/` directory (in addition to the existing root entry if any). Each tool's vendor stays current automatically; security patches and minor-version bumps arrive as PRs without ever touching the main `composer.json`. Adding a future tool means dropping a new directory under `tools/` and appending a single block to `.github/dependabot.yml`.

## Capabilities

### New Capabilities

- `developer-tooling`: covers the project's developer-only tooling — quality tools (lint/format/static analysis), pre-commit hook, CI quality gate. The capability describes the contract between the repo and contributors: which tools run when, where their dependencies live, and what the hook does.

### Modified Capabilities

(none — this change adds a new capability without changing any shipped behaviour)

## Impact

- **No production impact** — tools live under `tools/`, never autoloaded by the application kernel, never pulled by `composer install --no-dev` on the deploy host.
- **Existing contributors** — first time `composer install` runs locally, a post-install script offers to point `core.hooksPath` at `.githooks/`. Declining is fine; CI still enforces.
- **CI** — gains a `composer quality` step. Existing `composer test` is unchanged.
- **PR review surface** — stylistic noise drops; reviewers focus on substance. Wrong-version PHP-CS-Fixer or Rector configs no longer creep into the main lockfile.
- **Disk** — `tools/<tool>/vendor/` directories add ~50–100 MB locally (gitignored).
