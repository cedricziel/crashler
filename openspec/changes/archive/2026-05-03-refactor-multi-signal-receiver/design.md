## Context

Crashler currently receives OTLP logs via a path that's roughly 80% generic HTTP scaffolding (auth integration, Content-Type dispatch, gzip decoding, body-size limits, JSON-error-envelope mapping) and 20% logs-specific (decoders, DTO flattening, Parquet column layout). Adding traces and metrics by copy-paste would triple the surface area of the generic 80% — three controllers, three ingest services with the same try/catch shape, three places to remember to update if a behaviour changes.

This change is the first of three that takes us to a multi-signal receiver. It ships no user-visible features. It does change the on-disk Parquet schema for logs, but Crashler treats that schema as internal: a planned query layer (see `query API shape`, deferred) will be the public read contract; ad-hoc DuckDB queries that exist today are documented as "developer/operator tooling, not a stable interface."

The work also captures decisions made during exploration that would otherwise be re-litigated when we add traces:

- Promote OpenTelemetry semantic-convention attributes to first-class columns rather than relying on `json_extract` over a blob.
- Prefix resource-derived column names with `resource_` and scope-derived with `scope_` so the OTel hierarchy is visible at the schema level.
- Make schemas YAML-driven and versioned so future evolution is a config edit + minor code change, not a fork-and-recompile.
- Reserve a `transforms:` block in the schema YAML for ingest-time mutations (drop, rename, redact, derive) so the format we ship is forward-compatible with the ingest-transforms capability we plan to add later.

## Goals / Non-Goals

**Goals:**

- Extract a single `OtlpRequestPipeline` service that owns the signal-generic ingest behaviours; verify by rewriting `OtlpLogsController` as a thin delegator that calls it.
- Define a YAML schema format that captures column layout and semantic-convention promotion rules (with room reserved for future transforms).
- Implement a `SchemaCatalog` that loads, validates, and exposes those YAML files at boot. Boot must fail fast on a malformed schema.
- Promote the universal Tier-1 OTel resource attributes (`service.name`, `service.namespace`, `service.version`, `service.instance.id`, `deployment.environment(.name)`, `host.name`, `telemetry.sdk.language`) and a few logs-specific record-level keys (`event.name`, `exception.type`, `exception.message`) to columns; everything else stays in `*_attributes_json`.
- Migrate the existing logs schema to v1 of the YAML format with the new column names. Existing prod files are dropped at deploy.
- Every Parquet file written carries `_schema_version` (int32) and `_schema_id` (string) columns; file-level Parquet metadata carries the same triple plus the YAML's content hash, where flow-php exposes the API.
- Keep all 186 existing tests green; only update tests that read written rows back (asserting the new column names).
- Make the cost of adding the next two signals roughly: (a) write a `<signal>/v1.yaml`, (b) implement two decoders, (c) implement a `<Signal>IngestService` that flattens a DTO via `AttributeColumnExtractor`. No new generic plumbing.

**Non-Goals:**

- Implementing the `transforms:` block. The format is reserved and validated as empty in v1; tier-1 declarative ops (drop_keys, rename_keys, defaults, redact_keys) land in a future `add-ingest-transforms` change.
- Adding the trace and metric signals — those are separate changes.
- A query layer. Tools that read the Parquet files today (DuckDB) continue to do so; we just document that the schema is internal.
- A "hot reload" path for schema YAML edits. Schemas are part of the code commit; deploys validate them.
- Compaction, retention, S3, deletion, multi-flusher, async ingest — none of those are scoped here.
- Foundry factories or tier-2 mocks — the existing CapturingParquetWriter pattern continues to work.

## Decisions

### D1. Composition over inheritance for the pipeline extraction

**Decision.** Extract `App\Otlp\OtlpRequestPipeline` as a service that owns the signal-generic concerns. Each signal's controller is a thin class with its own `#[Route]` attribute that constructs the right decoders + ingest service and calls into the pipeline. There is no `AbstractOtlpController` base class.

**Why.** Inheritance hierarchies grow; methods that work for two signals get awkward overloads for the third. The pipeline is a single concrete service with a clear input/output contract, so testing happens once and refactoring is safe.

**Alternatives considered.**

- *Abstract controller + template-method.* Tighter syntax at first; opens room for "and one more virtual method" creep. Rejected.
- *Single multiplexed controller* with a `{signal}` route placeholder. Hides the `#[Route]` attributes that make routing discoverable; debug:router output gets less useful. Rejected for now (could be reconsidered).

### D2. Schema YAML is the single source of truth for a signal's row shape

**Decision.** A schema YAML file at `config/schemas/<signal>/v<n>.yaml` declares:

- A `signal` (string), `version` (int) header.
- An ordered `columns:` list with `name`, `type` (`int32`/`int64`/`string`/`boolean`/`float`/`dateTime`), and `repetition` (`required`/`optional`).
- A `promotions:` block with `resource:`, `scope:`, and `record:` sub-maps from semantic-convention key to column name.
- A reserved `transforms:` block whose sub-keys (`drop_keys`, `rename_keys`, `defaults`, `redact_keys`, `derive`, `drop_when`) must be present and validated as empty in v1.

**Why.** Centralising the row shape in YAML lets future changes evolve the schema without touching code beyond the signal-specific decoder + ingest service. Versioning means we can add v2 without breaking files already on disk; readers fan out across versions.

**The YAML is the contract.** The PHP `SchemaDefinition` value object is a parsed, validated mirror of the YAML — never a separate source of truth.

### D3. _schema_version and _schema_id are universal Parquet columns

**Decision.** Every Parquet file written by Crashler — across logs, traces, metrics, and any future signal — carries two universal columns at the end of its declared schema:

- `_schema_version` (int32, REQUIRED) — copied verbatim from the YAML's `version` field.
- `_schema_id` (string, REQUIRED) — `<signal>/v<version>`, e.g. `logs/v1`.

These columns are added by `ParquetFileWriter`, not by signal-specific schemas. Schema YAML files do not declare them.

**Why.** A file's schema identity is a universal property; embedding it as columns means DuckDB queries can branch on schema version trivially (`WHERE _schema_version >= 2 …`) and the cost is negligible (RLE+dictionary encoding compresses constant columns to ~4 bytes per file). When flow-php exposes file-level key/value metadata via `Options::KEY_VALUE_METADATA` or equivalent, we *also* write `crashler.schema_id`, `crashler.schema_version`, and `crashler.schema_yaml_sha256` there for self-describing files; if the API isn't accessible, we live with column-only and document the gap.

**Why the underscore prefix.** Distinguishes infrastructure columns from data columns. Adopted convention: anything starting with `_` is Crashler bookkeeping, not user-visible attribute data.

### D4. Promotion rules apply at three levels with three column-name conventions

**Decision.**

```
   level         column-name prefix       source                   examples
   ───────────── ──────────────────────── ──────────────────────── ───────────────────────
   resource      resource_*               ResourceLogs.resource    resource_service_name,
                                          .attributes              resource_host_name
   scope         scope_*                  ScopeLogs.scope.*        scope_name (already),
                                          (top-level fields)       scope_schema_url
   record        <unprefixed>             LogRecord.attributes,    severity_text, body_json,
                                          plus signal-specific     event_name, exception_type
                                          fields                   (planned: http_request_method
                                          (computed at flatten)    for traces)
```

Promotion is a *shadow*: the value lives both in its column AND remains in `resource_attributes_json` / `attributes_json`. Renaming or removing a column never loses data — the JSON blob is the lossless source of truth.

**Why.** With three signals all writing the same universal resource fields, an unprefixed `service_name` column becomes ambiguous when reading across signals. The prefix turns reads into "I know exactly which OTel concept produced this column."

### D5. Existing prod log files are dropped at change-1 deploy

**Decision.** A new Deployer task `crashler:purge_old_logs` runs once during the change-1 deploy, removing all `<deploy_path>/shared/var/share/logs/**` files before `deploy:vendors`. Hostside log volumes go from ~12 hours-old smoke-test files back to empty.

**Why.** The data has zero retention value (smoke tests against an unfinished pipeline). The alternatives — keep both column conventions in parallel until compaction normalises — adds query-time complexity for no benefit. Document the rewrite-or-drop pattern (option α from exploration) as the canonical migration approach for *future* schema-internal changes; option γ (one-shot file rewriter) is the right pattern when there's real data on disk.

The task is opt-in via an env flag (`CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1`) so it can't accidentally fire on a host that *does* have meaningful data.

### D6. Compile-time validation in CrashlerExtension

**Decision.** `CrashlerExtension::load()` glob-scans `config/schemas/**/v*.yaml` at container compile time, parses each via `SchemaDefinition::fromYaml()`, and aggregates them into a `SchemaCatalog` definition. Any malformed YAML fails container compilation with a clear error. The validation rules:

- Filename matches `<signal>/v<int>.yaml`; the `<signal>` and `<int>` match the YAML's `signal` / `version` fields.
- `columns` is non-empty; every column has `name`, `type` (in the allowed set), and `repetition` (in the allowed set).
- Column names are unique.
- Column names matching `_schema_*` are reserved (writer-emitted); schemas can't declare them.
- `promotions` keys (`resource`, `scope`, `record`) are present (each may be empty); column targets in promotions exist in `columns`.
- `transforms` block has the documented six sub-keys, each present and currently empty in v1.

**Why.** Failing fast at boot is the spec-driven discipline we're already applying for tenants. It's far better to fail in `cache:clear` during deploy than at first request.

### D7. SignalDecoder<T> and IngestsSignal<T> contracts

**Decision.**

```php
interface App\Otlp\Contract\SignalDecoder
{
    /** @return object the signal-specific top-level DTO */
    public function decode(string $body): object;
}

interface App\Otlp\Contract\IngestsSignal
{
    public function write(object $request, App\Tenancy\Tenant $tenant): void;
}
```

Generic `<T>` isn't expressible as a real PHP generic, so we use `object` and rely on the controller's wiring to type-match decoder ↔ service. Tests for each signal-specific implementation cover the type assumption.

**Why.** Two narrow interfaces keep the pipeline parameterisable without hauling in a generics library. The cost is a single `instanceof` worth of type discipline at the wiring point.

### D8. AttributeColumnExtractor as the single promotion gate

**Decision.** A `App\Otlp\AttributeColumnExtractor` service is constructed with a `SchemaDefinition`. It exposes `extractResource`, `extractScope`, and `extractRecord` methods, each returning a map of `column-name => scalar value` for the attributes that match a promotion rule, leaving the input list untouched.

**Why.** Centralising promotion means the rules lookup happens once per request and the JSON blob serialisation continues to use the unmodified original list (the lossless source). Per-signal ingest services don't need to know which keys are promoted — they always pass the full list and the extractor decides.

### D9. Legacy semconv-key fallback (`deployment.environment` → `deployment.environment.name`)

**Decision.** The promotion rule for `resource_deployment_environment` lists *both* canonical key (`deployment.environment.name`) and the legacy alias (`deployment.environment`); the extractor picks the first non-null match. Other dual-name semconv keys can use the same pattern in the YAML.

**Why.** OTel renamed the key in 2024 but the older form is still emitted by older SDKs and most existing code. Supporting both unblocks deployments without forcing client-side upgrades. The dual-listing is data, not code, so it's a YAML edit.

### D10. The `transforms:` block ships reserved-and-validated, with no implementation

**Decision.** Every schema YAML in v1 includes the full `transforms:` skeleton:

```yaml
transforms:
  drop_keys: []
  rename_keys: {}
  defaults: { resource: {}, record: {} }
  redact_keys: []
  derive: {}
  drop_when: []
```

`SchemaDefinition` validates the skeleton's presence and shape; non-empty entries are explicitly rejected ("transforms not yet implemented"). Future changes flip individual sub-keys from "rejected" to "applied" without changing the YAML format.

**Why.** Locking in the format now means schema authors writing v1 see the full surface and don't get surprised when transforms land. Validating "must be empty" ensures nobody accidentally writes a transform that's silently ignored.

### D11. PartitionPathResolver gains a signal parameter

**Decision.** `PartitionPathResolver::resolve(Tenant $tenant, string $signalSubdir): PartitionPaths`. Existing call sites pass `'logs'`. Future signals pass `'traces'` and `'metrics'`.

**Why.** The path layout becomes `<storage_root>/<signal>/<tenant>/date=…/hour=…/`. Tenant directory isolation continues to be a security boundary; signal directory separation is operational ("delete all metrics older than X" becomes a single rm -rf).

## Risks / Trade-offs

- **Bad-abstraction risk from extracting on one example.** Mitigation: D1 (composition not inheritance) keeps the abstraction surface small; tests verify behaviour parity, not internal structure; if traces/metrics force a redesign, only the pipeline service needs surgery, not three controller hierarchies.
- **YAML schema format ossifies before traces/metrics are implemented.** Mitigation: schemas are versioned. We can ship `logs/v2.yaml` if v1's shape proves inadequate; readers handle multiple versions via `_schema_version`.
- **flow-php's file-level metadata API may not be what we expect.** If `Options::KEY_VALUE_METADATA` (or whatever the actual constant is) doesn't expose file-level KV cleanly, we degrade to row-level columns only and document the gap. This is purely a "nice to have"; the row-level columns are sufficient for query routing.
- **Compile-time YAML validation slows `cache:clear`.** Negligible at v1 (one schema, ~50 lines). Plausibly meaningful when there are 10+ schemas and many transform rules. Acceptable; alternative is runtime validation at first request, which is worse.
- **`_schema_version` / `_schema_id` columns "pollute" the user-visible row shape.** Documented as Crashler-internal (underscore prefix). Query writers may filter them out in projections. Cost is negligible.
- **Existing prod files are deleted.** Documented as option α; the deploy task is opt-in via `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1` so a future operator with real retention concerns gets a clear opt-in checkpoint.
- **AttributeColumnExtractor does the promotion work even for keys absent from the request.** Net-zero cost (associative lookup of N rules over M input keys is O(N+M)); for typical OTLP requests N is tens, M is dozens — sub-millisecond.

## Migration Plan

This change is internally breaking for the on-disk logs schema. Rollout sequence:

1. Author and validate `config/schemas/logs/v1.yaml` locally; full test suite green.
2. Push to `main`; CI runs the suite on PHP 8.5 with the new schema. CI must stay green.
3. Set `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1` in the deploy environment for the change-1 deploy.
4. Run `dep deploy production`. The deploy task purges existing log Parquet files before `deploy:vendors` copies the new code.
5. Smoke-test `POST /v1/logs` with a valid token; verify a Parquet file lands at the expected path with the new column names + `_schema_id=logs/v1`.
6. Unset `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY` (or revert it to its default) so subsequent deploys don't purge.

Rollback: revert the change-1 commit and redeploy. Files written under v1 remain on disk; they become unreadable by the older code path. Acceptable because: (a) only ~minutes of v1 writes accumulate during the rollback window, and (b) the smoke-test data has zero retention value just like the v0 files we purged.

## Open Questions

- **Where does compile-time validation actually run for `App\:` autoloaded services that depend on `SchemaCatalog`?** If `LogsParquetSchema` (a service) needs the catalog at construct-time, the catalog must be built before the schema service is autowired. Likely fine because extensions run before services.yaml's autoload; will verify during implementation.
- **Should `_schema_version` be `int32` or `string`?** Using int32 forces version numbers to fit in 31 bits; using string lets a future `1.0.0` semver work. Decision: int32, because integer queries on schema version are clearer and we have no plan to use semver.
- **Do we need a schema lint command?** A local `dep crashler:schema:lint` task could parse YAML files without booting the kernel — useful in editor save-hooks. Out of scope for this change; possible follow-up if YAML edit volume grows.

## Implementation findings (verified during apply)

- **flow-php Parquet file-level KV metadata is not exposed via the public API.** Inspecting `vendor/flow-php/parquet/src/Flow/Parquet/Option.php` confirms there's no `KEY_VALUE_METADATA` Option case, and `Writer` doesn't accept a footer-metadata argument on `open()` or `openForStream()`. The best-effort clause in the schema-catalog spec ("Parquet file-level schema metadata") therefore degrades to row-level columns only. The boot-time warning called for in scenario "Row-level columns sufficient when file metadata unavailable" is the implementation choice. A future change can revisit if flow-php gains the capability or if we replace the writer engine.
