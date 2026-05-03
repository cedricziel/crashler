## Why

Crashler needs a primary ingestion path. OTLP/HTTP-JSON is the de-facto open standard for shipping logs from any modern OpenTelemetry SDK or Collector, and storing the result as partitioned Parquet on local disk gives us a queryable, columnar lake without committing to a heavier table format (Iceberg, Delta) before we know the access patterns. The implementation deliberately optimizes for *simplicity over throughput*: the request handler writes the Parquet file inline, with no write-ahead log, no background worker, and no console commands.

## What Changes

- New `POST /v1/logs` endpoint accepting OTLP/HTTP-JSON request bodies (`application/json`, optional `Content-Encoding: gzip`), returning the OTLP `ExportLogsServiceResponse` shape
- Bearer-token authentication on `/v1/logs`; tokens are tenant-scoped and revocable
- Tenant identities and the SHA-256 hashes of their valid tokens are configured in a Symfony config file (`config/packages/crashler.yaml`); there is no database storage for tenants or tokens and no admin UI or CLI for managing them in v1
- The request handler synchronously encodes a Parquet file containing every log record in the request and commits it to disk via `.tmp + rename` before returning 200. The endpoint blocks on Parquet encode, fsync, and rename.
- Partitioned local-disk layout: `<APP_SHARE_DIR>/logs/<tenant_slug>/date=YYYY-MM-DD/hour=HH/part-<ulid>.parquet`. Date and hour are derived from the request's wall-clock arrival time (UTC); per-record event timestamps are preserved in the `time_unix_nano` column.
- One Parquet file per accepted request. Cross-request batching, file compaction, and a background flusher are explicitly out of scope.
- DuckDB query recipes documented in the README for ad-hoc reads.

## Capabilities

### New Capabilities
- `tenants`: tenant identity model (config-file-driven), and authentication of inbound OTLP requests against the configured token hashes
- `log-ingest`: HTTP API for receiving OTLP/HTTP-JSON log payloads, validating them, and dispatching them to the Parquet writer
- `log-storage`: synchronous, in-handler Parquet writing with partitioned on-disk layout and atomic file commit

### Modified Capabilities
<!-- None — there are no prior specs to modify. -->

## Impact

- **New dependency**: `flow-php/parquet` (pure-PHP Parquet writer). Compression codec selection requires the matching PHP extension at deploy time; v1 will use `GZIP` (no extension needed) with `ZSTD` (`ext-zstd`) as a configurable opt-in.
- **No new database tables**: this change adds zero Doctrine migrations.
- **New routes and controllers**: `POST /v1/logs` plus a security firewall scoped to that path.
- **New configuration**: `config/packages/crashler.yaml` holds the tenant/token map; `APP_SHARE_DIR` is reused as the storage root; Parquet compression and request size limits are env-tunable.
- **No new console commands**.
- **Out of scope (deliberately deferred to follow-up changes)**: write-ahead logging or any durable queue between HTTP and Parquet, asynchronous ingest, file compaction, retention/expiry, OTLP/protobuf binary encoding, OTLP traces and metrics, S3/object-storage backend, a read API, attribute storage as native Parquet `map<string,string>` (v1 uses JSON-string columns), database-backed tenants/tokens, and any admin UI or CLI for tenant management.
- **Tradeoffs accepted in v1**: per-request response latency includes Parquet encoding (typically tens to hundreds of ms for normal batch sizes); each request produces one file, which over time creates a small-files problem that a future compaction change must address; transient handler failures return 5xx and rely on the OTLP client's at-least-once retry semantics rather than a server-side durable buffer.
