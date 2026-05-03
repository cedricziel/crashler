**Methodology.** Strict red-green-refactor. Every implementation task is preceded by a failing test for the smallest meaningful behaviour. `[red]` writes a failing test; `[green]` makes it pass with the minimum code; `[refactor]` reshapes with tests green. Refactor tasks (no [tag]) reorganise existing code without changing behaviour and must be verified by the existing test suite remaining green without modifications.

Behaviour-parity guard for the refactor portion: at the end of group 4, every test that existed before this change must still pass with no changes to its body. Tests that *do* need updating (because they read written rows back and the row column names changed) are listed explicitly in group 9.

## 1. Project setup and groundwork

- [x] 1.1 Verify `symfony/yaml` is reachable from `App\` services (it is in deps; just sanity-check the autoload path)
- [x] 1.2 Investigate flow-php's file-level key/value metadata API: read the relevant `Writer` / `Options` source under `vendor/flow-php/parquet/` and write a one-paragraph note in design.md's "Open Questions" section confirming whether `Options::KEY_VALUE_METADATA` (or the actual constant name) is reachable — *finding: not exposed; row-level columns only, design.md updated*
- [x] 1.3 Add a `tests/Unit/Schema/` and `tests/Component/Schema/` directory; confirm `composer test:unit` still runs (zero new tests yet)

## 2. SchemaDefinition value object (TDD)

- [x] 2.1 [red] Test: `SchemaDefinition::fromArray(['signal' => 'logs', 'version' => 1, 'columns' => [...], 'promotions' => [...], 'transforms' => $emptySkeleton])` returns a definition exposing `signal`, `version`, `id`, `columns`, three promotion maps
- [x] 2.2 [green] Implement `App\Schema\SchemaDefinition` with named-constructor `fromArray(array)`
- [x] 2.3 [red] Test: `id` is `<signal>/v<version>` (`logs/v1`)
- [x] 2.4 [green] Trivially in `fromArray`
- [x] 2.5 [red] Test: `yamlSha256` set by `fromYamlString(string $yaml)` named-constructor; equals `hash('sha256', $yaml)`
- [x] 2.6 [green] Implement `fromYamlString` using `Symfony\Component\Yaml\Yaml::parse`
- [x] 2.7 [red] Test: empty `columns` rejected via `InvalidArgumentException`
- [x] 2.8 [red] Test: duplicate column names rejected with the duplicates listed in the error message
- [x] 2.9 [red] Test: a column with `type: hex` (or any unrecognised type) rejected naming the offending column and value
- [x] 2.10 [red] Test: a column with `repetition: required` and a name beginning with `_schema_` rejected
- [x] 2.11 [red] Test: any column with `repetition` outside `{required, optional}` rejected
- [x] 2.12 [green] Implement column-list validation in `SchemaDefinition::fromArray`
- [x] 2.13 [red] Test: `promotions.resource.<key> -> <column>` where `<column>` not in `columns` rejected with the offending key/column pair
- [x] 2.14 [green] Implement promotion-target validation
- [x] 2.15 [red] Test: `transforms` block missing any of the six required sub-keys rejected, error names the missing sub-key
- [x] 2.16 [red] Test: `transforms.drop_keys: [some.key]` (non-empty) rejected with "transforms not yet implemented"
- [x] 2.17 [green] Implement transforms-skeleton validation
- [x] 2.18 [red] Test: legacy-fallback promotion (a column listed under multiple keys) accepted

## 3. Schema YAML loader (TDD)

- [x] 3.1 [red] Test: `SchemaCatalog::fromDirectory($dir)` scans `<dir>/<signal>/v<n>.yaml`, returns a catalog with one entry per file
- [x] 3.2 [green] Implement `App\Schema\SchemaCatalog` with `fromDirectory` static factory using `glob`
- [x] 3.3 [red] Test: filename signal/version mismatching YAML header rejected (e.g. `logs/v2.yaml` declaring `version: 1`); error names the file
- [x] 3.4 [green] Add filename/header consistency check
- [x] 3.5 [red] Test: `byId('logs/v1')` returns the matching definition; `byId('logs/v99')` throws
- [x] 3.6 [green] Implement
- [x] 3.7 [red] Test: `latestFor('logs')` returns highest version when both v1 and v2 are present (use a fixture dir with two files)
- [x] 3.8 [green] Implement
- [x] 3.9 [red] Test: `latestFor('unknownsignal')` throws with the signal name
- [x] 3.10 [red] Test: `all()` returns all definitions keyed by id
- [x] 3.11 [green] Implement
- [x] 3.12 [red] Test: a malformed YAML in the directory raises during construction with a path-aware error
- [x] 3.13 [green] Wrap the parse loop with file-path context

## 4. SchemaCatalog wired into the container (component TDD)

- [x] 4.1 [red] Component test: build a fresh `ContainerBuilder`, run `CrashlerExtension::load` against a fixture YAML directory, assert `SchemaCatalog` is registered as a public service and resolves with the expected entries — *implemented via ValidateSchemasPass + factory definition rather than load(), since validation must run at compile time*
- [x] 4.2 [green] Update `CrashlerExtension::load` to scan `config/schemas/**/v*.yaml`, build a SchemaCatalog definition, set as parameter or service via factory — *split: compile-time validation in ValidateSchemasPass, runtime build via services.yaml factory*
- [x] 4.3 [red] Component test: a malformed YAML in the fixture dir causes container compilation to fail; error message references the file
- [x] 4.4 [green] Surface YAML errors as `InvalidConfigurationException` from the extension
- [x] 4.5 [red] Component test: an empty `config/schemas/` directory (no YAML files) is accepted; `SchemaCatalog::all()` returns empty
- [x] 4.6 Wire `services.yaml` to expose `App\Schema\SchemaCatalog` (likely via a factory pattern mirroring `TenantRegistryFactory`)

## 5. AttributeColumnExtractor (TDD)

- [x] 5.1 [red] Test: extractor constructed with a stub schema whose `promotions.resource.service.name = 'resource_service_name'`; `extractResource` on `[KeyValueDto('service.name', stringValue('checkout'))]` returns `['resource_service_name' => 'checkout']`
- [x] 5.2 [green] Implement `App\Otlp\AttributeColumnExtractor` with `extractResource` happy path
- [x] 5.3 [red] Test: input `KeyValueDto[]` is unchanged after extraction (reference equality)
- [x] 5.4 [green] Confirm by-value extraction; refactor if needed
- [x] 5.5 [red] Test: keys not listed in promotions are absent from the returned map
- [x] 5.6 [red] Test: scope and record extraction work via separate methods with their own promotion sub-maps
- [x] 5.7 [green] Implement `extractScope` and `extractRecord`
- [x] 5.8 [red] Test: legacy-key fallback — a single column listed under both `deployment.environment.name` and `deployment.environment`, input contains only the legacy form, returned map uses that value
- [x] 5.9 [green] Implement first-non-null semantics
- [x] 5.10 [red] Test: AnyValue variants — int values become PHP int in the column, double becomes float, string becomes string, bool becomes bool, bytes becomes the raw byte string, kvlist/array values are coerced to JSON-string (since columns are scalar)
- [x] 5.11 [green] Implement variant-to-scalar conversion

## 6. logs/v1 YAML

- [x] 6.1 [red] Test (component): `SchemaCatalog::latestFor('logs')` against the real `config/schemas/` dir returns a definition with the documented columns and promotion rules
- [x] 6.2 Author `config/schemas/logs/v1.yaml` per the log-storage delta spec table
- [x] 6.3 [green] Test from 6.1 passes
- [x] 6.4 [red] Test: every column listed in the spec table is present with the documented type and repetition
- [x] 6.5 [red] Test: every documented promotion rule is present in the loaded definition
- [x] 6.6 [red] Test: legacy `deployment.environment` is listed alongside the canonical `deployment.environment.name` for the same column

## 7. ParquetFileWriter — universal _schema_* columns (TDD, component)

- [ ] 7.1 [red] Component test: a writer constructed with a `SchemaDefinition` for `logs/v1` plus compression `GZIP` produces a Parquet file whose final schema contains every YAML-declared column AND `_schema_version` (int32 REQUIRED) AND `_schema_id` (string REQUIRED) appended at the end
- [ ] 7.2 [green] Update `App\Storage\ParquetFileWriter` constructor to accept a `SchemaDefinition` (not a raw flow-php Schema) and append the universal columns when building the flow-php `Schema::with(...)`
- [ ] 7.3 [red] Component test: every row in the produced file carries `_schema_version = 1` and `_schema_id = 'logs/v1'`
- [ ] 7.4 [green] Update `writeAndCommit` (or the row construction site) to inject these two columns onto every row
- [ ] 7.5 [red] Component test (skip-if-unsupported): when flow-php exposes file-level KV metadata, the produced file's footer contains keys `crashler.schema_id`, `crashler.schema_version`, `crashler.schema_yaml_sha256` with their expected values
- [ ] 7.6 [green] If 1.2's investigation confirmed support, wire the metadata; otherwise mark 7.5 skipped with a recorded reason and emit a one-time boot warning per the spec

## 8. PartitionPathResolver — signalSubdir parameter (TDD)

- [ ] 8.1 [red] Test: `resolve(Tenant, 'logs')` returns paths under `<root>/logs/<slug>/...`; `resolve(Tenant, 'traces')` returns paths under `<root>/traces/<slug>/...`
- [ ] 8.2 [green] Update `PartitionPathResolver::resolve` signature; update the path template
- [ ] 8.3 Update existing call site in `LogsIngestService` to pass `'logs'` (failing tests fixed)

## 9. LogsIngestService — populate promoted columns (TDD)

- [ ] 9.1 [red] Test (unit): given a request whose resource attributes contain `service.name`, `service.namespace`, `host.name`, `telemetry.sdk.language`, the produced rows have those values in `resource_service_name`, `resource_service_namespace`, `resource_host_name`, `resource_telemetry_sdk_language` columns AND those values still appear inside `resource_attributes_json`
- [ ] 9.2 [green] Inject `AttributeColumnExtractor`; merge resource-promoted columns into each row
- [ ] 9.3 [red] Test: when `service.name` is absent, `resource_service_name` is `null` (or absent in the row map)
- [ ] 9.4 [red] Test: scope-level promotion — a scope with `schema_url = 'https://opentelemetry.io/schemas/1.30.0'` populates `scope_schema_url` on every row produced from that scope
- [ ] 9.5 [green] Add scope extraction (note: scope's `schema_url` is a top-level scope field in OTel, NOT inside `Scope.attributes`; the extractor needs to handle this)
- [ ] 9.6 [red] Test: record-level promotion — a LogRecord with `event.name = 'foo'` and `exception.type = 'RuntimeException'` produces a row with `event_name = 'foo'` and `exception_type = 'RuntimeException'`
- [ ] 9.7 [green] Add record extraction
- [ ] 9.8 [red] Test: legacy `service_name` column is no longer emitted — opening a produced Parquet file does NOT show a `service_name` column in its schema
- [ ] 9.9 [green] Confirm (the schema YAML doesn't list it)

## 10. OtlpRequestPipeline extraction (refactor; tests must stay green unmodified)

- [ ] 10.1 Define `App\Otlp\Contract\SignalDecoder` interface with a single `decode(string $body): object` method
- [ ] 10.2 Define `App\Otlp\Contract\IngestsSignal` interface with a single `write(object $request, Tenant $tenant): void` method
- [ ] 10.3 `App\Otlp\LogsJsonDecoder` and `App\Otlp\LogsProtobufDecoder` declare `implements SignalDecoder`; `App\Logs\LogsIngestService` declares `implements IngestsSignal`. No body changes — `composer test` stays green.
- [ ] 10.4 Extract `App\Otlp\OtlpRequestPipeline` whose `handle()` method holds the controller's body verbatim, parameterised on `SignalDecoder $jsonDecoder, SignalDecoder $protobufDecoder, IngestsSignal $ingestService`. The current `OtlpLogsController::__invoke` becomes a one-liner that builds the args and calls into the pipeline.
- [ ] 10.5 Wire the pipeline as a service in `services.yaml`; `OtlpLogsController` constructor takes the pipeline + the three logs-specific collaborators
- [ ] 10.6 [verification] Run `composer test`. All 186 existing tests must pass without any test body changes. Tests that *would* need to change (because they assert internal column names) are tracked separately in group 11.

## 11. Update behaviour-parity tests for the new schema (test-side updates only)

- [ ] 11.1 `tests/Component/Logs/LogsIngestServiceComponentTest.php`: update assertions that read written rows back to use `resource_service_name` (was `service_name`); add assertions for the new promoted columns (`resource_service_namespace`, `scope_schema_url`, `event_name`, etc. where the fixture exercises them)
- [ ] 11.2 `tests/Functional/Controller/OtlpLogsControllerTest.php`: same column-rename adjustments where the test reads rows back; verify response shape and status codes are untouched
- [ ] 11.3 `tests/Component/Storage/ParquetFileWriterTest.php`: update the synthetic row fixtures to use the new column names; assert `_schema_version` and `_schema_id` columns appear with expected values
- [ ] 11.4 `composer test` green

## 12. Deploy task — purge old log files (gated)

- [ ] 12.1 Add `crashler:purge_old_logs` Deployer task in `deploy.php` that removes `<deploy_path>/shared/var/share/logs/**` when `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1`; otherwise no-op with an info log
- [ ] 12.2 Wire `before('deploy:vendors', 'crashler:purge_old_logs')`
- [ ] 12.3 Document the env flag in `.env.deploy.example` and the README

## 13. Documentation

- [ ] 13.1 README: add a "Schemas and column conventions" section pointing at `config/schemas/<signal>/v<n>.yaml`, listing the resource_/scope_/record-level prefixes, and documenting `_schema_version`/`_schema_id` as Crashler-internal
- [ ] 13.2 README: clarify that the on-disk Parquet schema is internal (a query layer is planned) and that DuckDB recipes are operator tooling rather than a stable public interface
- [ ] 13.3 README "Querying" section: update the example DuckDB query to use `resource_service_name` (the rename) and demonstrate filtering on `_schema_version`

## 14. Spec coverage cross-check

- [ ] 14.1 Walk every `#### Scenario:` block in `specs/schema-catalog/spec.md` and confirm a unit/component test covers it
- [ ] 14.2 Walk every modified scenario in `specs/log-storage/spec.md` and confirm coverage
- [ ] 14.3 Add tests for any unmapped scenario

## 15. Final validation

- [ ] 15.1 `composer test` passes with zero deprecations/notices/warnings
- [ ] 15.2 `composer test:coverage` passes the configured thresholds (where coverage driver is available)
- [ ] 15.3 `openspec validate refactor-multi-signal-receiver --strict` passes
- [ ] 15.4 Set `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1` and `dep deploy production`; verify smoke test produces a new file at the expected path with `_schema_id = 'logs/v1'` and the new column names
- [ ] 15.5 Unset `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY` for subsequent deploys
