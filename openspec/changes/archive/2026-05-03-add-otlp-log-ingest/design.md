## Context

Crashler is a green-field Symfony 8.0 / PHP 8.4 / Doctrine / Postgres project. There are no prior controllers, entities, or specs. This is the foundational ingestion pipeline.

A previous draft of this change proposed a Postgres write-ahead log fronting a long-running flusher worker, with console commands for tenant and token management. After review, that design was rejected in favor of maximal simplicity: the request handler writes the Parquet file directly, tenant config lives in a Symfony YAML file, and there are no console commands at all. This document records the simpler design.

Operating constraints relevant to this design:

- Single deployment must serve multiple tenants (multi-tenant from day one).
- Local-disk storage only in v1; the design must not preclude an object-storage backend later.
- "Stick to PHP" — no Python/Java/Rust sidecar.
- No CLI commands. Tenant lifecycle is config-as-code (file edits + redeploys).
- `flow-php/parquet` was vetted: pure-PHP Parquet writer; supports needed primitive types; supports `GZIP` (no extension) and `ZSTD/SNAPPY/LZ4/BROTLI` (each requiring its respective PHP extension); accepts a stream resource for output (enabling `.tmp + rename` atomic writes).

## Goals / Non-Goals

**Goals:**

- Accept OTLP/HTTP-JSON log payloads from any standard OpenTelemetry SDK or Collector exporter.
- Authenticate every request against a tenant-scoped bearer token; reject unauthenticated requests with 401.
- Land each accepted request as exactly one Hive-partitioned Parquet file under `<APP_SHARE_DIR>/logs/<tenant_slug>/date=…/hour=…/` so it is queryable by DuckDB without a catalog.
- Provide a strong durability contract: a 200 response means the Parquet file has been fsync'd and renamed into its final path on disk.
- Keep total moving parts as small as possible — one HTTP endpoint, one config file, one writer service.

**Non-Goals:**

- OTLP protobuf-binary encoding (deferred — JSON is sufficient for v1).
- OTLP traces and metrics (separate future changes).
- Object-storage backends (S3/MinIO/GCS).
- A write-ahead log, message queue, or any durable buffer between the HTTP layer and Parquet.
- Asynchronous ingest, background workers, or any process other than the web server.
- Console commands of any kind (tenant CRUD, token issuance, flush, compaction).
- File compaction, retention/expiry, deletion.
- A read API; v1 is "write-only", reads happen via DuckDB on the file tree.
- Native Parquet `map<string,string>` for attributes (v1 uses JSON-string columns).
- Database-backed tenants or tokens; database migrations of any kind in this change.
- Iceberg or any table format on top of the Parquet files.

## Decisions

### D1. Handler writes the Parquet file inline; no queue

**Decision.** `POST /v1/logs` calls a single `LogsIngestService::write($dto, $tenant)` method that flattens the OTLP envelope to row arrays, opens a `.tmp` Parquet file, writes all rows, closes and fsyncs the writer, and renames the file to its final partitioned path. The HTTP response is sent only after the rename returns successfully. There is no write-ahead log, no Symfony Messenger dispatch, and no background process.

**Why.** With no WAL and no flusher, the simplest correct design is the controller calling the writer directly. An intermediate Messenger sync-transport dispatch buys nothing here: there is no durable queue to swap in later that wouldn't itself be a separate change. Adding indirection now would be cargo-culted from the previous design.

**Alternatives considered.**

- *Symfony Messenger sync transport between controller and writer.* Justified in the prior design as an upgrade path to async; without a WAL or a worker, async would itself be a sweeping change, so the indirection has no current or near-future benefit.
- *Write-ahead log + background flusher.* Rejected by the user as too much machinery for v1.

### D2. Tenant identity and tokens come from a config file, not a database

**Decision.** `config/packages/crashler.yaml` defines a list of tenants. Each tenant has a slug, a display name, and a list of SHA-256 hashes of valid bearer tokens. At application boot, this config is loaded into an in-memory map keyed by token hash. Authentication is an O(1) map lookup. There are no `tenants` or `ingest_tokens` tables.

```yaml
# config/packages/crashler.yaml
crashler:
  tenants:
    acme:
      name: 'Acme Corp'
      token_hashes:
        - '5b8aa5a2d2c872e8321cf37308d69df2a4c5b7e8f9d0c1a2b3c4d5e6f7a8b9c0'
    widget-co:
      name: 'Widget Co'
      token_hashes:
        - 'a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00'
```

**Why.** The user excluded console commands, which removes the natural admin path for DB-backed tenant/token management. Config-as-code is the next-simplest option, and it eliminates two entities, two repositories, two migrations, and a token-hashing service from the change.

**Operational consequences.**

- Generating a token: operator runs `openssl rand -hex 32` (or any other source), records the SHA-256 of the chosen plaintext in `crashler.yaml`, distributes the plaintext to the user.
- Revoking a token: remove the hash from `crashler.yaml` and redeploy.
- The plaintext token is never stored anywhere by crashler; only its hash is configured.
- Symfony's environment-variable substitution in YAML (`%env(...)%`) is available if the operator prefers to keep token hashes in env vars / a secret store.

**Alternatives considered.**

- *DB-backed entities with management via a future admin API.* Pushes complexity to v2 and leaves v1 with no usable bootstrap.
- *Single-tenant via env vars only.* Violates the multi-tenant requirement.
- *JIT tenant creation on first valid token.* Magical, hard to reason about.

### D3. Tenant is a physical path prefix, not a Hive partition column

**Decision.** Files are written to `<APP_SHARE_DIR>/logs/<tenant_slug>/date=YYYY-MM-DD/hour=HH/part-<ulid>.parquet`. The `<tenant_slug>` segment is a plain path component, not `tenant=<slug>` Hive-style.

**Why.** Tenant separation is a security boundary, not a query-pruning optimization. A tenant's data can never be returned by a query rooted in another tenant's directory; cross-tenant joins require deliberate path globbing. Filesystem-level access control (and future per-tenant export, GDPR-style purges, or per-tenant S3 buckets) all become trivial. Hive partitions inside the tenant directory still give DuckDB pruning on `date` and `hour`.

### D4. Slug rules: `[a-z][a-z0-9-]{2,31}`, must not end with `-`

Becomes a path component. Filesystem-safe, URL-safe, predictable.

### D5. Bearer-token auth via in-memory hash map

**Decision.** Tokens are arbitrary opaque strings chosen by the operator. The system computes SHA-256 of the presented bearer token (UTF-8 bytes) and looks it up in the in-memory map built from `crashler.yaml` at boot. On hit, the matching tenant is attached to the request context. On miss, 401.

**Why.** O(1) lookup, constant-time hash compare (PHP's `hash_equals` after the lookup, since the map keys are themselves the search input). No database round-trip on the ingest hot path. Tokens at rest are hashed (in the config file).

### D6. Partition derived from ingest time, not per-record event time

**Decision.** The `date` and `hour` segments of the file path are derived from `now()` UTC at the moment the request is handled. Records' actual `time_unix_nano` values are preserved in the corresponding Parquet column.

**Why.** A single request can carry records spanning multiple time buckets (a backfilled batch, a re-emitted backlog, etc.). Partitioning by ingest time means one request → one file, which keeps the writer simple. Partitioning by per-record event time would require fan-out — multiple files per request — which directly contradicts the simplicity goal.

**Consequences.** Late-arriving logs land in the partition matching their ingest time, not their event time. Queries that care about event-time ranges must filter on the `time_unix_nano` column rather than relying on partition pruning. This is a documented limitation; a future change can introduce per-event-time fan-out if needed, without changing the file format.

### D7. WAL schema: denormalize resource attributes onto every row

OTLP nests `ResourceLogs → ScopeLogs → LogRecord` to deduplicate resource and scope on the wire. At rest in Parquet, normalization is wasted: column compression handles repeated values extremely well (RLE + dictionary encoding), and queries don't have to join across three levels. Each row carries its parent resource's attributes and scope info as denormalized columns.

### D8. Attributes stored as JSON strings, not native Parquet maps (v1 only)

`attributes_json` and `resource_attributes_json` are written as Parquet `string` columns containing the JSON-encoded attribute object. OTLP attribute values are `AnyValue` (string, int, double, bool, bytes, array, kvlist); coercing all of those into `map<string,string>` is lossy. JSON strings preserve fidelity and are universally supported by query engines. Promotable later without changing semantics.

### D9. Atomic file commit via `.tmp` + POSIX rename

`flow-php/parquet` writes to a stream opened on `…/part-<ulid>.parquet.tmp`. On `close()` we `fsync()` the file descriptor and `rename()` to `…/part-<ulid>.parquet`. POSIX guarantees `rename()` within the same filesystem is atomic — readers either see the absence or the new fully-formed file, never a partial Parquet footer.

If any step (write, close, fsync, rename) fails, the handler returns 5xx, the `.tmp` file is unlinked, and the OTLP client retries the request per its at-least-once contract.

### D10. Compression: GZIP default, ZSTD opt-in via env

`GZIP` is in stock PHP and good enough. `ZSTD` requires `ext-zstd`; we surface it as `CRASHLER_PARQUET_COMPRESSION` so a deployment that ships the extension can opt in without code changes. The handler validates at boot (or first use) that the configured codec's extension is loaded, failing fast with a clear error.

### D11. ULID for filenames

ULIDs sort by creation time lexically. `…/date=2026-05-03/hour=14/part-01J…parquet` lists in roughly write order, which simplifies debugging and any future compaction step.

### D12. Testability is a first-class design constraint

**Decision.** Every service that has a non-trivial behavior receives its dependencies through the constructor. The handful of things that historically leak into production code as ambient state are explicitly injected:

- **Time.** A `Psr\Clock\ClockInterface` is injected wherever "now" is needed (`PartitionPathResolver`, `LogsIngestService`). Production wires `Symfony\Component\Clock\NativeClock`; tests use `Symfony\Component\Clock\MockClock` with a pinned timestamp. No service calls `time()`, `microtime()`, `new \DateTimeImmutable('now')`, or `date()` directly.
- **Filenames.** A small `App\Storage\FilenameGenerator` interface produces the per-file ULID. Production wires a Symfony `UlidFactory`-backed implementation; tests inject a deterministic stub that returns predictable values.
- **Storage root.** Resolved as a Symfony container parameter (`%crashler.storage_root%`) bound from `APP_SHARE_DIR`, never read from `$_ENV` / `getenv()` inside services. Tests pass an alternative parameter value (typically a per-test temp directory) by overriding the parameter in a test kernel.
- **Compression codec.** Resolved as a parameter bound from `CRASHLER_PARQUET_COMPRESSION`; the validation that the required PHP extension is loaded happens at compile-time in the DI extension, not at runtime in the writer.
- **Tenant configuration.** `TenantRegistry` is built once at compile-time from the validated `crashler.tenants` tree. Tests construct `TenantRegistry` directly with a small fixture map; no YAML loading needed for unit tests.

This rules out static state, singletons, global functions, and `getenv()` calls in service code. Every test has a fresh, fully-determined object graph with no surprise side channels.

### D13. Test pyramid: unit / component / functional, TDD-driven, zenstruck-assisted

**Decision.** Tests are organized into three categories living in three top-level directories under `tests/`. PHPUnit is configured with three named suites so each can run independently and in CI parallel jobs.

- `tests/Unit/` — pure logic, no I/O of any kind (no filesystem, no network, no kernel boot). Targets: DTOs, decoders, validators, slug rules, hash format validation, schema construction, path resolver (with injected clock), compression resolver, tenant registry, error-response helper. Fast, parallel-safe, run on every save.
- `tests/Component/` — touches the real local filesystem inside a per-test temp directory; does not boot the Symfony kernel. Targets: `ParquetFileWriter` round-trip (write rows, re-read, assert schema and content), atomic-rename success and failure paths, `.tmp` cleanup on abort. Each test creates its temp dir in `setUp` and deletes it in `tearDown`.
- `tests/Functional/` — boots the Symfony kernel via `WebTestCase` plus `zenstruck/browser` for fluent HTTP assertions. Targets: the controller end-to-end against a real authenticator, real registry, real writer, with a per-test storage root parameter override and a pinned `MockClock`. Covers the full HTTP contract: success paths, all 4xx, all 5xx, gzip path, oversized body, schema mismatch.

**Methodology.** Every implementation task is preceded by a failing test for the smallest meaningful behavior. Strict red-green-refactor: write the test, see it fail for the right reason, write the minimum code to pass, refactor with tests green. Larger features are decomposed into multiple red-green cycles rather than written in one shot. Tests are checked in alongside the production code that satisfies them; commits should ideally pair the failing-test commit with the make-it-pass commit (or atomically squash them).

**Library choices for tests.**

- **zenstruck/browser** for functional tests — fluent assertions over Symfony's BrowserKit (`$browser->post('/v1/logs', ['json' => $payload])->assertStatus(200)->assertJson(...)`). Cleaner than raw `WebTestCase` and naturally maps to the OTLP request shape.
- **zenstruck/foundry** for test fixtures — pure-object factories for DTOs and OTLP payloads (no persistence; we have no entities to persist). Factories live in `tests/Factories/`.
- **zenstruck/assert** for ad-hoc assertion helpers when PHPUnit's defaults are clumsy.

These are dev-only dependencies. Production code never imports from `Zenstruck\*`.

**Per-test isolation rules:**

- Every functional test gets a unique storage root: `sys_get_temp_dir() . '/crashler-test-' . bin2hex(random_bytes(8))`, removed in `tearDown`.
- Every functional test pins the clock to a known instant via a kernel-level service override.
- Tests must not depend on each other's order; PHPUnit's default randomized order is enabled.
- No global filesystem under `var/share/` is touched by any test.

### D14. Coverage target and gating

**Decision.** Line coverage thresholds enforced by PHPUnit's `--coverage-threshold` (or equivalent) flag in the `composer test` script:

- Pure-logic units (everything in `App\Otlp\`, `App\Tenancy\`, `App\Storage\` *except* `ParquetFileWriter`): **95%** line, **90%** branch.
- I/O-touching units (`ParquetFileWriter`, controller): **85%** line.
- Project-wide aggregate: **90%** line.

Every `#### Scenario:` block in the three capability spec files corresponds to at least one test case. The change is not considered apply-complete until both the coverage thresholds pass and every spec scenario has a mapped test (a comment marker like `// spec: tenants/Bearer token authentication on /v1/logs/Valid token authenticates` next to each test method).

Coverage is collected with PCOV (faster than Xdebug) where available, falling back to Xdebug. Both are documented as developer setup options in the README.

## Risks / Trade-offs

- **Per-request latency includes Parquet encoding.** A request with a few hundred records adds ~50–200 ms; a request with thousands can take longer. → Mitigation: documented; OTLP exporters tolerate latency well. If it becomes painful, the next-step is to reintroduce a queue (a separate change).
- **Small-files explosion.** Every request → one file. Busy exporters create thousands of small Parquet files per hour. → Mitigation: a compaction change is the planned follow-up. The directory layout is compaction-friendly. DuckDB tolerates many files; query speed degrades but does not break.
- **No durable retry buffer.** If the handler fails mid-write, the request returns 5xx and the OTLP client retries. There is no server-side at-least-once. → Mitigation: rely on OTLP exporter retry semantics (which the spec mandates). Operationally, this is what most OTLP receivers do.
- **Late-arriving logs land in the wrong partition.** Queries must filter on `time_unix_nano`, not partition columns, for event-time ranges. → Mitigation: documented in README; revisitable as a future change.
- **Tenant management requires a redeploy.** Adding/revoking a tenant or token involves a config change and process restart. → Mitigation: acceptable for v1's expected operational tempo. A future change can add a runtime admin path if needed; the interface (token hash → tenant) is stable across that migration.
- **Memory pressure on huge requests.** flow-php buffers a row group in memory before flushing. A pathological request with millions of records could OOM the worker. → Mitigation: enforce `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES` (default 16 MiB) at the request layer; cap `Option::ROW_GROUP_SIZE_BYTES` (default 32 MiB) in the writer.
- **Token theft.** A leaked bearer token grants ingest until the config is updated and redeployed. → Mitigation: hashed at rest, revocation requires a redeploy. HTTPS termination is the deployer's responsibility.
- **Concurrent requests writing to the same partition.** Two PHP-FPM workers handling different requests for the same tenant in the same hour will write into the same directory. → Mitigation: ULID filenames eliminate collisions; each file is independent. No coordination needed.
- **Coverage thresholds slow down red-loop iteration.** Running coverage on every test invocation is slower than running tests bare. → Mitigation: developers run `composer test` (no coverage) during the inner loop; `composer test:coverage` is the gate before commit. CI runs the gated form.

## Migration Plan

Green-field change with no prior production data. Rollout is: ship code, ship `config/packages/crashler.yaml` with at least one tenant entry, deploy. There are no schema migrations. Rollback is reverting the deployment.

## Open Questions

- **Where do operators record the plaintext labels for token hashes?** A YAML comment is the obvious answer (`# label: prod-collector`). Should we formalize that as a structured field next to each hash? *Tentative answer: leave as comments in v1; a structured field can be added without breaking existing config.*
- **Should we allow per-tenant compression-codec overrides?** Probably not in v1 — a single global codec keeps deployment simple. Revisit if a tenant has a specific hardware constraint.
- **How do we surface request-handling latency metrics?** Symfony's web profiler is fine for dev. Production observability is out of scope here; will be addressed in a future change that adds OTel instrumentation to crashler itself.
