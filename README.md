# Crashler

A self-hosted log/error sink that receives OpenTelemetry logs over OTLP/HTTP-JSON and stores them as Hive-partitioned Parquet files on local disk.

## Status

In active development. The first feature — OTLP/HTTP-JSON log ingest with multi-tenant authentication and per-request Parquet writes — is implemented under [`openspec/changes/add-otlp-log-ingest/`](openspec/changes/add-otlp-log-ingest/).

## Requirements

- PHP 8.4 with `ext-ctype`, `ext-iconv`
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

Each successful `POST /v1/logs` request produces exactly one Parquet file:

```
$APP_SHARE_DIR/logs/
  <tenant-slug>/
    date=YYYY-MM-DD/
      hour=HH/
        part-<ulid>.parquet
```

`<tenant-slug>` is the authenticated tenant's slug. Date and hour are derived from the request's wall-clock arrival time in UTC. Per-record event timestamps are preserved in the `time_unix_nano` column inside the file.

## Querying

DuckDB reads the file tree directly:

```bash
duckdb -c "
  SELECT severity_text, body_json, attributes_json
  FROM read_parquet('var/share/logs/<slug>/**/*.parquet', hive_partitioning=true)
  WHERE date = '2026-05-03'
    AND severity_number >= 17
  LIMIT 100;
"
```

Event-time range queries should filter on `time_unix_nano` rather than the partition columns: a late-arriving log lands in the partition corresponding to its *ingest* time, not its event time.

## Running

```bash
symfony serve         # or your web server of choice
```

Send a test request from the OpenTelemetry Collector's `otlphttp` exporter or any OTel SDK pointing at `http://localhost:8000/v1/logs` with `Authorization: Bearer <plaintext-token>`.

## Tests

Tests are organized into three suites:

- `tests/Unit/` — pure logic, no I/O
- `tests/Component/` — touches the local filesystem in temp directories; does not boot the kernel
- `tests/Functional/` — full Symfony kernel via `WebTestCase` + `zenstruck/browser`

```bash
composer test            # run all suites, no coverage (fast inner-loop)
composer test:coverage   # run all suites with coverage and enforce thresholds
```

## Design constraints (v1)

These are deliberate choices made for the first release; future changes will revisit them:

- **One Parquet file per request.** No write-ahead log, no batching across requests.
- **Synchronous Parquet write.** A 200 response means the file is fsync'd and renamed into its final path. The HTTP request waits on Parquet encoding.
- **Partition by ingest time, not event time.** Late-arriving logs are queryable but require column-level time filters.
- **Tenant changes require a redeploy.** No runtime CRUD for tenants or tokens.
- **No background workers, no console commands.** The web server is the only running process.
- **At-least-once via client retry.** A 5xx response means the request was fully rejected; the OTLP client retries the full batch. The server keeps no buffer.
