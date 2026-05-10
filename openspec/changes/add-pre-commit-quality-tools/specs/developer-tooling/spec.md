## ADDED Requirements

### Requirement: Quality tool dependencies live under `tools/<tool>/`

Each developer-only quality tool (PHPStan, PHP-CS-Fixer, Rector, plus any future additions) SHALL ship its dependencies in an isolated `tools/<tool>/` directory carrying its own `composer.json` and `composer.lock`. The main `composer.json` SHALL NOT carry these tools as `require-dev` entries. The deploy command (`composer install --no-dev`) SHALL NOT install any `tools/*/vendor/` directories.

#### Scenario: Each shipped tool has its own composer.json
- **WHEN** an operator inspects the repo
- **THEN** `tools/phpstan/composer.json`, `tools/php-cs-fixer/composer.json`, and `tools/rector/composer.json` exist
- **AND** the main `composer.json` does NOT list `phpstan/phpstan`, `friendsofphp/php-cs-fixer`, or `rector/rector` as dependencies

#### Scenario: Tool vendors are gitignored
- **WHEN** a contributor runs `composer tools:install`
- **THEN** `tools/<tool>/vendor/` directories are populated locally
- **AND** `.gitignore` excludes `tools/*/vendor/` from version control
- **AND** the per-tool `composer.lock` files ARE tracked

#### Scenario: Production install ignores tools
- **WHEN** the deploy host runs `composer install --no-dev` against the main `composer.json`
- **THEN** no quality-tool packages are installed
- **AND** `tools/<tool>/vendor/` directories are not created on the production release

### Requirement: Top-level composer scripts proxy into tool directories

The main `composer.json` SHALL expose convenience scripts that invoke each tool from its isolated `vendor/` directory. The script names SHALL be: `tools:install`, `tools:update`, `phpstan`, `cs:check`, `cs:fix`, `rector`, `rector:dry`, and `quality`. The `quality` script SHALL chain `cs:check` and `phpstan` so that one command runs the full check-only quality gate.

#### Scenario: tools:install populates every tool vendor
- **WHEN** a contributor runs `composer tools:install`
- **THEN** every `tools/<tool>/vendor/` directory is populated to match its `composer.lock`
- **AND** the script exits non-zero if any tool fails to install

#### Scenario: phpstan script invokes the isolated binary
- **WHEN** a contributor runs `composer phpstan`
- **THEN** the script invokes `tools/phpstan/vendor/bin/phpstan analyse` (not a top-level binary)

#### Scenario: quality script runs the full check-only gate
- **WHEN** a contributor or CI runs `composer quality`
- **THEN** PHP-CS-Fixer runs in `--diff` check mode (no auto-fix)
- **AND** PHPStan runs against the project
- **AND** the script exits non-zero if either tool reports a problem

### Requirement: Project-root configuration files

PHPStan, PHP-CS-Fixer, and Rector configuration SHALL live at the project root (`phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `rector.php`). The configuration files SHALL exclude `vendor/`, `var/`, `tools/*/vendor/`, and `migrations/` from analysis. Each tool's binary SHALL find its config without explicit `--config` flags.

#### Scenario: PHPStan reads phpstan.dist.neon
- **WHEN** `composer phpstan` runs
- **THEN** PHPStan reads `phpstan.dist.neon` from the repo root without any `-c`/`--config` flag

#### Scenario: PHP-CS-Fixer reads .php-cs-fixer.dist.php
- **WHEN** `composer cs:check` runs
- **THEN** PHP-CS-Fixer reads `.php-cs-fixer.dist.php` from the repo root

#### Scenario: Excluded directories are not analysed
- **WHEN** any tool runs
- **THEN** files under `vendor/`, `var/`, `tools/*/vendor/`, and `migrations/` are not analysed
- **AND** files under `src/`, `tests/`, and `config/` are analysed

### Requirement: Tracked pre-commit hook with opt-in activation

The repo SHALL ship a `.githooks/pre-commit` script (executable, tracked in git). Activation SHALL be opt-in via `git config core.hooksPath .githooks`. The composer post-install script SHALL print a one-line activation hint when the hook is not yet active and the install runs in an interactive terminal; it SHALL NOT mutate `git config` automatically.

#### Scenario: Hook script is tracked and executable
- **WHEN** an operator inspects `.githooks/pre-commit`
- **THEN** the file exists, is tracked in git, and is marked executable (`chmod +x`)

#### Scenario: composer install hints at activation when hook is inactive
- **WHEN** a contributor runs `composer install` in an interactive terminal
- **AND** `git config core.hooksPath` is unset or does not point at `.githooks`
- **THEN** the post-install output prints a one-line activation hint suggesting `git config core.hooksPath .githooks`
- **AND** no git config is changed

#### Scenario: Non-interactive install does not print the hint
- **WHEN** `composer install` runs in CI (non-TTY) or with `--no-interaction`
- **THEN** no activation hint is printed
- **AND** no prompts block the install

### Requirement: Pre-commit hook auto-fixes style and runs static analysis

When activated, the pre-commit hook SHALL execute the following steps in order, aborting the commit (exit non-zero) on the first failure:

1. Identify staged `.php` files via `git diff --cached --name-only --diff-filter=ACM`. If none, skip steps 2–3.
2. Run PHP-CS-Fixer in fix mode against the staged files. If any files were modified, re-stage them with `git add` and continue (do not abort).
3. Run PHPStan against the entire project. Abort the commit on non-zero exit.

The hook SHALL NOT run Rector. The hook SHALL NOT run PHPUnit. Both are available via composer scripts but are too slow for the pre-commit hot path.

#### Scenario: Style is auto-fixed and re-staged
- **WHEN** a contributor commits a file with style violations
- **THEN** PHP-CS-Fixer auto-fixes the file
- **AND** the fixed version is re-staged via `git add`
- **AND** the commit proceeds without aborting

#### Scenario: Static analysis failure aborts the commit
- **WHEN** PHPStan reports a problem against the project
- **THEN** the hook exits non-zero
- **AND** the commit does not land

#### Scenario: Hook with no staged PHP files skips the style step
- **WHEN** a commit changes only README.md or a config file
- **THEN** PHP-CS-Fixer is not invoked
- **AND** PHPStan still runs (the project state may have changed via merge / rebase)

#### Scenario: Hook missing tool vendors fails fast with a hint
- **WHEN** the hook runs but `tools/phpstan/vendor/bin/phpstan` does not exist
- **THEN** the hook prints a clear error pointing at `composer tools:install`
- **AND** exits non-zero

### Requirement: Dependabot tracks every tool manifest plus the root

The repo SHALL carry `.github/dependabot.yml` declaring a `package-ecosystem: composer` entry for every `tools/<tool>/` directory and one entry for the project root. Each entry SHALL set `directory:` to the tool's path (or `/` for root), `schedule.interval: weekly`, and `open-pull-requests-limit: 5`. Adding a new quality tool to `tools/` SHALL be accompanied by the corresponding Dependabot entry in the same change.

#### Scenario: Each tools/ directory has a Dependabot entry
- **WHEN** an operator inspects `.github/dependabot.yml`
- **THEN** there is one `composer` entry whose `directory:` matches `/tools/phpstan`
- **AND** one whose `directory:` matches `/tools/php-cs-fixer`
- **AND** one whose `directory:` matches `/tools/rector`
- **AND** one whose `directory:` matches `/`

#### Scenario: Adding a tool without a Dependabot entry is rejected in review
- **WHEN** a future change adds `tools/psalm/` (or any new tool directory)
- **AND** the change does not also add a corresponding `composer` block to `.github/dependabot.yml`
- **THEN** the change is treated as incomplete

#### Scenario: Dependabot opens PRs against the tool's lockfile
- **WHEN** an upstream tool releases a new version
- **THEN** Dependabot opens a PR modifying only `tools/<tool>/composer.lock` (and `tools/<tool>/composer.json` if version constraints relax)
- **AND** the main `composer.json` and `composer.lock` are NOT touched by the PR

### Requirement: CI runs the same checks as the hook via composer scripts

CI SHALL execute `composer quality` as a build step. The step SHALL fail the build if either `cs:check` or `phpstan` returns non-zero. CI SHALL NOT invoke the `.githooks/pre-commit` script directly (the hook's auto-fix-and-re-stage behaviour is wrong for CI).

#### Scenario: CI uses the same tools at the same versions as local runs
- **WHEN** CI invokes `composer quality`
- **THEN** PHP-CS-Fixer and PHPStan run from `tools/<tool>/vendor/bin/` at the versions pinned by `tools/<tool>/composer.lock`
- **AND** identical project state produces identical output local and on CI

#### Scenario: CI fails on style drift
- **WHEN** a PR introduces a style violation that bypassed the local hook (e.g., committed with `--no-verify`)
- **THEN** the CI `composer quality` step fails the build
- **AND** the failing diff is visible in the CI log
