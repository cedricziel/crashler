# Crashler

A self-hosted log/error sink that receives OpenTelemetry logs over OTLP/HTTP-JSON and stores them as Hive-partitioned Parquet files on local disk.

## Status

In active development. The first feature — OTLP/HTTP-JSON log ingest with multi-tenant authentication and per-request Parquet writes — is implemented under [`openspec/changes/add-otlp-log-ingest/`](openspec/changes/add-otlp-log-ingest/).

## Requirements

- PHP 8.5 with `ext-ctype`, `ext-iconv`
- Composer 2
- Postgres 16 (used by Symfony / Doctrine; the log ingest path itself does not write to the DB in v1)

### Optional PHP extensions

- `ext-zstd` — required if `CRASHLER_PARQUET_COMPRESSION=ZSTD`
- `ext-snappy` — recommended for `SNAPPY` compression (works in pure PHP without it but is materially slower)
- `ext-brotli`, `ext-lz4` — required for the corresponding compression codecs
- `pcov` (or `xdebug`) — required for `composer test:coverage`. PCOV is the recommended choice for speed.

## Configuration

### Tenants and ingest tokens

Tenants are configured statically in `config/packages/crashler.yaml`. Each tenant has a slug (used as a filesystem path component), a display name, and a list of SHA-256 hashes of valid bearer tokens. Plaintext tokens are never stored.

To generate a token, pick any random string and record its SHA-256 hex:

```bash
TOKEN="cw_$(openssl rand -hex 16)"
echo "Token (give to client): $TOKEN"
echo "Hash (put in crashler.yaml): $(printf '%s' "$TOKEN" | shasum -a 256 | cut -d' ' -f1)"
```

Add the hash under the appropriate tenant in `config/packages/crashler.yaml`, then redeploy. Tokens are revoked by removing their hash from the config and redeploying.

### Storage root

`APP_SHARE_DIR` (default `var/share`) is the root of the Parquet file tree. The kernel fails to boot if the directory does not exist or is not writable.

### Compression

`CRASHLER_PARQUET_COMPRESSION` selects the Parquet codec (default `GZIP`). See `.env` for the full list and extension requirements.

## On-disk layout

Each successful OTLP write produces exactly one Parquet file under the tenant's signal subdirectory:

```
$APP_SHARE_DIR/<signal>/
  <tenant-slug>/
    date=YYYY-MM-DD/
      hour=HH/
        part-<ulid>.parquet
```

`<signal>` is `logs` for `POST /v1/logs` and `traces` for `POST /v1/traces`. `<tenant-slug>` is the authenticated tenant's slug. Date and hour are derived from the request's wall-clock arrival time in UTC. Per-record event timestamps are preserved inside the file (logs: `time_unix_nano`; traces: `start_time_unix_nano`).

## Schemas and column conventions

Each signal's Parquet layout is declared in a versioned YAML at `config/schemas/<signal>/v<n>.yaml`. Today: [`logs/v1`](config/schemas/logs/v1.yaml) (one row per `LogRecord`) and [`traces/v1`](config/schemas/traces/v1.yaml) (one row per `Span`, with `events` and `links` carried as JSON-string columns). The YAML lists every column (name, type, repetition), the OpenTelemetry semantic-convention promotions that turn well-known attributes into top-level columns, and a reserved `transforms:` block for future ingest-time mutations.

Promoted column names follow a three-level prefix convention:

```
resource_*        from ResourceLogs.resource.attributes
                  e.g. resource_service_name, resource_host_name,
                  resource_deployment_environment

scope_*           from ScopeLogs.scope (top-level fields and, eventually,
                  scope.attributes)
                  e.g. scope_name, scope_version, scope_schema_url

<unprefixed>      record-level fields and per-record promoted attributes
                  e.g. severity_text, body_json, time_unix_nano,
                  event_name, exception_type
```

Promoted columns are **shadows**: every promoted attribute also stays in `resource_attributes_json` / `attributes_json` blobs unchanged. Renaming or removing a column is a YAML edit (with a version bump for breaking changes); the JSON blob is the lossless source of truth.

Two infrastructure columns are appended by the writer to every file regardless of signal:

- `_schema_version` (int32) — copied from the YAML's `version`.
- `_schema_id` (string) — `<signal>/v<version>`, e.g. `logs/v1`.

The on-disk Parquet schema is treated as **internal**. A future query layer will be the public read contract; ad-hoc DuckDB queries described below are operator tooling, not a stable public interface.

## Querying

DuckDB reads the file tree directly. Logs:

```bash
duckdb -c "
  SELECT
    _schema_id,
    resource_service_name,
    severity_text,
    body_json,
    attributes_json
  FROM read_parquet('var/share/logs/<slug>/**/*.parquet', hive_partitioning=true)
  WHERE date = '2026-05-03'
    AND severity_number >= 17
    AND resource_service_name = 'checkout'
  LIMIT 100;
"
```

Traces:

```bash
duckdb -c "
  SELECT
    _schema_id,
    resource_service_name,
    name,
    kind_text,
    duration_nano,
    http_response_status_code,
    status_text,
    status_message
  FROM read_parquet('var/share/traces/<slug>/**/*.parquet', hive_partitioning=true)
  WHERE _schema_id = 'traces/v1'
    AND date = '2026-05-03'
    AND kind_text = 'SERVER'
    AND http_response_status_code >= 500
  ORDER BY start_time_unix_nano DESC
  LIMIT 100;
"
```

Event-time range queries should filter on `time_unix_nano` (logs) or `start_time_unix_nano` (traces) rather than the partition columns: a late-arriving record lands in the partition corresponding to its *ingest* time, not its event time. Multi-version reads can branch on `_schema_version`/`_schema_id` to handle column renames across schema versions.

## Running

```bash
symfony serve         # or your web server of choice
```

Send a test request from the OpenTelemetry Collector's `otlphttp` exporter or any OTel SDK. Both signals share the same auth header (`Authorization: Bearer <plaintext-token>`); the URLs are:

- Logs: `http://localhost:8000/v1/logs`
- Traces: `http://localhost:8000/v1/traces`

## Tests

Tests are organized into three suites:

- `tests/Unit/` — pure logic, no I/O
- `tests/Component/` — touches the local filesystem in temp directories; does not boot the kernel
- `tests/Functional/` — full Symfony kernel via `WebTestCase` + `zenstruck/browser`

```bash
composer test            # run all suites, no coverage (fast inner-loop)
composer test:coverage   # run all suites with coverage and enforce thresholds
```

## Deployment

Deployer is pre-configured for the Symfony recipe in [`deploy.php`](deploy.php). Hosts come **purely from environment variables** so no hostnames or paths land in this public repo.

For each stage you deploy to, set `${STAGE}_DEPLOY_*` variables — either as real env vars or in a gitignored `.env.deploy` (see [`.env.deploy.example`](.env.deploy.example) for the schema). Stages with no `HOST` set are silently skipped.

```bash
# Local one-shot:
PRODUCTION_DEPLOY_HOST=server.example.com \
PRODUCTION_DEPLOY_PATH=/var/www/crashler \
PRODUCTION_DEPLOY_USER=deployer \
  dep deploy production

# Or copy .env.deploy.example to .env.deploy, fill in values, then:
dep deploy production
```

The Symfony recipe handles cache clearing, vendor install (`--no-dev --optimize-autoloader`), and Symfony console wiring. Shared dirs persist across releases:

- `var/log` — application logs
- `var/share` — ingested Parquet files (deploys never re-emit existing files)

Shared file `.env.local` carries per-host secrets (e.g. `DATABASE_URL`, `CRASHLER_*` overrides).

## Design constraints (v1)

These are deliberate choices made for the first release; future changes will revisit them:

- **One Parquet file per request.** No write-ahead log, no batching across requests.
- **Synchronous Parquet write.** A 200 response means the file is fsync'd and renamed into its final path. The HTTP request waits on Parquet encoding.
- **Partition by ingest time, not event time.** Late-arriving logs are queryable but require column-level time filters.
- **Tenant changes require a redeploy.** No runtime CRUD for tenants or tokens.
- **No background workers, no console commands.** The web server is the only running process.
- **At-least-once via client retry.** A 5xx response means the request was fully rejected; the OTLP client retries the full batch. The server keeps no buffer.
- **Parquet schema is internal.** The query layer (planned, not yet shipped) will be the public read contract. Schema YAMLs are versioned so renames don't break existing files.

## Schema-breaking deploys

When a schema YAML rename or column removal lands, existing Parquet files become unreadable by the new column conventions. For deployments where the on-disk data has no retention value (e.g. early development), set `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1` for a single `dep deploy` invocation:

```bash
CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1 dep deploy production
```

This fires the `crashler:purge_old_logs` task before vendors install, removing every `*.parquet` under `<deploy_path>/shared/var/share/logs/`. **Unset the flag for subsequent deploys** so it can't accidentally fire on a host that has accumulated meaningful data. For migrations where data must be preserved, write a one-shot Deployer task that reads each old file and rewrites it with the new schema (option γ in the change's design.md).
