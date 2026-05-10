# Contributing to Crashler

This guide covers the load-bearing rules. Code style, commit conventions, and review nuance live in PR comments where they can be context-specific.

## Workflow

Crashler uses [OpenSpec](https://openspec.dev/) for spec-driven development. Every non-trivial change runs through three stages:

```
/opsx:propose <change-name>   → drafts proposal/design/specs/tasks
/opsx:apply   <change-name>   → implements the tasks
/opsx:archive <change-name>   → moves the change to archive/, syncs delta specs to living specs
```

The `openspec/` directory layout:

- `openspec/specs/<capability>/spec.md` — living specs (source of truth for shipped behaviour)
- `openspec/changes/<change-name>/` — in-flight proposals (proposal.md, design.md, specs/, tasks.md)
- `openspec/changes/archive/<YYYY-MM-DD>-<change-name>/` — archived proposals (historical record)

For a tiny one-off (typo fix, single-line refactor), running OpenSpec is overkill — make the PR directly. For anything that touches a documented behaviour, propose first.

## OpenAPI examples rule

Every read-API query parameter declared via API Platform MUST carry a realistic `example` value:

```php
'since' => new QueryParameter(
    description: '...',
    schema: ['type' => 'string'],
    openApi: new \ApiPlatform\OpenApi\Model\Parameter(
        name: 'since',
        in: 'query',
        example: '1h',
    ),
),
```

Realistic means: real-shape hex IDs (32 lowercase chars for `traceId`, 16 for `spanId`), OTLP-aligned severity numbers (`9` / `13` / `17`), real service names (`checkout`, `payments`), well-formed timestamps. Placeholder values like `"string"` or `"<value>"` are not acceptable.

The lint command checks the rule:

```bash
bin/console app:openapi:lint-examples
```

The lint runs as part of the test suite via `OpenApiLintExamplesTest::testLintPassesOnCurrentSpec`. CI fails on any missing example.

Rationale and full requirement: `openspec/specs/read-api/spec.md` (sections starting "OpenAPI document carries examples ...").

## Tests

Run the full suite:

```bash
vendor/bin/phpunit --no-coverage
# or
composer test
```

Subsuites:

```bash
composer test:unit         # tests/Unit/
composer test:component    # tests/Component/
composer test:functional   # tests/Functional/
```

When adding new behaviour:
- Unit tests for pure logic (parsers, accumulators, predicates).
- Component tests when a feature exercises real Parquet I/O via flow-php (typically writing a small fixture and reading it back).
- Functional tests when the change affects HTTP behaviour (use `KernelTestCase` + `zenstruck/browser` like the existing `tests/Functional/Read/*Test.php`).

## Conventions

**Dependencies.** Use `composer require <package>` rather than editing `composer.json` directly — Symfony Flex recipes (auto-generated config files, environment variable additions) only run when Composer's command is invoked. Manual edits skip the recipe step and leave the configuration in an inconsistent state.

**Database migrations: dual-platform.** Crashler runs **Postgres in dev/test** and **MariaDB in (some) production deployments**. Every migration MUST carry SQL for both platforms — there is no single dialect that works on both. Use the platform-branching pattern:

```php
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

public function up(Schema $schema): void
{
    $platform = $this->connection->getDatabasePlatform();

    if ($platform instanceof PostgreSQLPlatform) {
        $this->upPostgres();
        return;
    }
    if ($platform instanceof MariaDBPlatform) {
        $this->upMariaDb();
        return;
    }
    $this->abortIf(true, sprintf('Unsupported database platform: %s', $platform::class));
}

private function upPostgres(): void { /* Postgres-flavoured DDL */ }
private function upMariaDb(): void { /* MariaDB-flavoured DDL */ }
```

Apply the same shape to `down()`. See `migrations/Version20260509223424.php` for a worked example.

To author a new migration, run `bin/console doctrine:migrations:diff` against Postgres (your dev DB), then dump the MariaDB equivalent with:

```bash
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:53306/app?serverVersion=mariadb-11.0.0" \
  bin/console doctrine:schema:create --dump-sql
```

…and copy the relevant statements into the `upMariaDb()` method. Verify both branches by applying + rolling back against each platform locally before committing. A standalone MariaDB container for verification:

```bash
docker run --rm -d --name crashler-mariadb-test \
  -p 53306:3306 \
  -e MARIADB_ROOT_PASSWORD=root \
  -e MARIADB_DATABASE=app -e MARIADB_USER=app -e MARIADB_PASSWORD='!ChangeMe!' \
  mariadb:11
docker exec crashler-mariadb-test mariadb -u root -proot \
  -e "CREATE DATABASE IF NOT EXISTS app_test; GRANT ALL ON app_test.* TO 'app'@'%';"
```

Running the functional suite against MariaDB confirms the schema works end-to-end:

```bash
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:53306/app?serverVersion=mariadb-11.0.0" \
  composer test:functional
```

Common dialect differences to watch for:

| Concept                        | Postgres                                 | MariaDB                                                   |
|--------------------------------|------------------------------------------|-----------------------------------------------------------|
| Auto-increment column          | `INT GENERATED BY DEFAULT AS IDENTITY`   | `INT AUTO_INCREMENT`                                      |
| Timestamp column               | `TIMESTAMP(0) WITHOUT TIME ZONE`         | `DATETIME`                                                |
| Reserved-word table quoting    | `"user"`                                 | `` `user` ``                                              |
| Foreign-key clauses            | `... ON DELETE CASCADE NOT DEFERRABLE`   | `... ON DELETE CASCADE` (no `NOT DEFERRABLE`)             |
| Drop foreign key               | `ALTER TABLE x DROP CONSTRAINT FK_…`     | `ALTER TABLE x DROP FOREIGN KEY FK_…`                     |
| Index declarations             | Separate `CREATE INDEX` statements       | Inline inside `CREATE TABLE` or separate                  |
| `TEXT`                         | `TEXT`                                   | `LONGTEXT` (when used for unbounded payloads)             |
| Table options                  | (none)                                   | `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`|

**Read-side / write-side split.** The OTLP write path (`POST /v1/<signal>`) and the read API (`GET /v1/<signal>`, plus the POST search and aggregate endpoints) share storage but are otherwise independent codebases. Don't cross the boundary: a writer concern (signal-specific Parquet schema, attribute promotion) doesn't belong in `App\Read\`, and vice versa.

**Per-signal patterns.** Logs / traces / metrics each have their own ingest service, state provider, and (where applicable) controllers. When a behaviour applies to all three, look for a shared base (e.g. `BaseSearchStateProvider`, `PostSearchController`) rather than duplicating it three times.

## Questions or feedback

Open an issue at the project repository. For OpenSpec-specific questions (how do I propose a change? what's the difference between a delta and a living spec?), the openspec/AGENTS.md file at the repo root has the workflow reference.
