## ADDED Requirements

### Requirement: Schema YAML format

The system SHALL define each signal's Parquet row shape, semantic-convention promotion rules, and reserved transforms in a YAML file at `config/schemas/<signal>/v<n>.yaml`. Each file SHALL contain at minimum:

- `signal` (string) — the signal name (`logs`, `traces`, `metrics`, …).
- `version` (positive integer) — the schema version.
- `columns` (non-empty ordered list) — each entry an object with:
    - `name` (string) — Parquet column name.
    - `type` (string) — one of `int32`, `int64`, `string`, `boolean`, `float`, `dateTime`.
    - `repetition` (string) — `required` or `optional`.
- `promotions` (object with three sub-maps, any may be empty):
    - `resource:` mapping semantic-convention key → column name (column must exist in `columns`).
    - `scope:` same shape.
    - `record:` same shape.
- `transforms` (object) — reserved skeleton with the six sub-keys listed in the schema-catalog Requirement: Transforms block (drop_keys, rename_keys, defaults, redact_keys, derive, drop_when), all empty in the initial release.

The filesystem path SHALL match the YAML's `signal` and `version` fields. Column names beginning with `_schema_` SHALL be reserved and SHALL NOT appear in the YAML's `columns` list.

#### Scenario: Valid YAML is accepted
- **WHEN** `config/schemas/logs/v1.yaml` declares `signal: logs`, `version: 1`, a non-empty `columns` list, well-typed entries, and the required promotion + transforms keys
- **THEN** the schema is loaded into the catalog
- **AND** retrievable via `byId('logs/v1')` and `latestFor('logs')`

#### Scenario: Path/header mismatch rejected
- **WHEN** `config/schemas/logs/v2.yaml` declares `version: 1`
- **THEN** the application fails to boot with an error naming the offending file

#### Scenario: Invalid column type rejected
- **WHEN** any column declares a `type` outside the allowed set (e.g. `type: hex`)
- **THEN** the application fails to boot with an error naming the column and offending type

#### Scenario: Reserved column name rejected
- **WHEN** a YAML declares a column named `_schema_version`, `_schema_id`, or any name beginning with `_schema_`
- **THEN** the application fails to boot with a "reserved name" error

#### Scenario: Promotion target must exist
- **WHEN** a `promotions.resource` entry maps a semconv key to a column name not present in `columns`
- **THEN** the application fails to boot with an error naming the offending key/column pair

### Requirement: Schema catalog service

The system SHALL expose `App\Schema\SchemaCatalog` as a service that, at boot, loads every `config/schemas/<signal>/v<n>.yaml` and exposes:

- `byId(string $id): SchemaDefinition` where `$id` is `<signal>/v<version>`.
- `latestFor(string $signal): SchemaDefinition`.
- `all(): array<string, SchemaDefinition>` keyed by id.

The `SchemaDefinition` value object SHALL expose `signal`, `version`, `id`, ordered `columns`, `resourcePromotions`, `scopePromotions`, `recordPromotions`, and `yamlSha256` (lowercase hex).

#### Scenario: latestFor returns highest version
- **WHEN** `config/schemas/logs/` contains both `v1.yaml` and `v2.yaml`
- **THEN** `latestFor('logs')` returns the v2 definition

#### Scenario: byId returns the named version
- **WHEN** `config/schemas/logs/` contains v1 and v2
- **THEN** `byId('logs/v1')` returns v1
- **AND** `byId('logs/v2')` returns v2

#### Scenario: latestFor on unknown signal throws
- **WHEN** `latestFor('unknownsignal')` is called
- **THEN** an exception is thrown with a message naming the missing signal

#### Scenario: yamlSha256 is the content hash
- **WHEN** a SchemaDefinition is loaded
- **THEN** its `yamlSha256` is the lowercase SHA-256 of the on-disk YAML bytes

### Requirement: Compile-time validation

The application SHALL validate every schema YAML at container compile time. Boot SHALL fail with a clear error if any of the following holds:

- A YAML file in `config/schemas/**/v*.yaml` cannot be parsed.
- The filename and the YAML header disagree on signal or version.
- The `columns` list is empty, has duplicate names, contains a reserved name, or has an entry with an invalid `type` or `repetition`.
- A `promotions` entry maps a semconv key to a column not declared in `columns`.
- The `transforms` block omits any of the six required sub-keys.
- The `transforms` block contains a non-empty entry (until tier-1 ops ship in a future change).

#### Scenario: Compile-time failure surfaces at cache:clear
- **WHEN** an operator deploys a release whose YAML has an invalid `type`
- **THEN** the production Composer post-install `cache:clear` fails
- **AND** the deploy aborts before the new release is symlinked into `current`

#### Scenario: Empty transforms block accepted in v1
- **WHEN** a YAML's `transforms` is the documented empty skeleton
- **THEN** the schema is accepted

#### Scenario: Non-empty transform rejected in v1
- **WHEN** a YAML's `transforms.drop_keys` is non-empty
- **THEN** the application fails to boot with an error indicating transforms are not yet implemented

### Requirement: Universal _schema_version and _schema_id columns

For every signal's Parquet file produced by the system, the writer SHALL append two universal columns AFTER the schema-declared columns:

- `_schema_version` (int32, REQUIRED) — copied from the schema definition's `version`.
- `_schema_id` (string, REQUIRED) — `<signal>/v<version>`.

These columns SHALL NOT appear in any schema YAML's `columns` list (they are reserved and writer-emitted). Every row in the file carries the same value for both columns.

#### Scenario: Universal columns present in every file
- **WHEN** any Parquet file produced by the writer is opened
- **THEN** the file's schema includes `_schema_version` (int32 REQUIRED) and `_schema_id` (string REQUIRED) columns
- **AND** every row carries the same value for both

#### Scenario: Universal columns reflect the schema used
- **WHEN** the file was written for `logs/v1`
- **THEN** every row's `_schema_version` is `1` and `_schema_id` is `logs/v1`

### Requirement: Parquet file-level schema metadata

When the underlying Parquet writer's API exposes file-level key/value metadata, the system SHALL write the following keys into every produced file:

- `crashler.schema_id` — equal to the row-level `_schema_id`.
- `crashler.schema_version` — string form of the row-level `_schema_version`.
- `crashler.schema_yaml_sha256` — the loaded schema's `yamlSha256`.

If the underlying writer does not expose file-level metadata, the system SHALL still emit the row-level columns; the file-level metadata SHALL be best-effort.

#### Scenario: File metadata present when supported
- **WHEN** a Parquet file is produced and the writer exposes file-level metadata
- **THEN** reading the file's footer key/value metadata returns the three `crashler.*` keys with their expected values

#### Scenario: Row-level columns sufficient when file metadata unavailable
- **WHEN** the writer's file-metadata API is not accessible
- **THEN** the file is still produced with the row-level `_schema_*` columns intact
- **AND** the application emits a single boot-time warning indicating file-level metadata is unavailable

### Requirement: Transforms block reserved

Every schema YAML SHALL include a `transforms` block with the following six sub-keys present, each defaulted to its empty form:

```
transforms:
  drop_keys: []
  rename_keys: {}
  defaults:
    resource: {}
    record: {}
  redact_keys: []
  derive: {}
  drop_when: []
```

Until ingest-transforms are introduced in a future change, every sub-key MUST be empty (lists empty, objects empty). The catalog rejects any non-empty transform with a clear "not yet implemented" error.

#### Scenario: Empty skeleton accepted
- **WHEN** a YAML's `transforms` matches the documented empty skeleton
- **THEN** the schema validates and loads

#### Scenario: Missing sub-key rejected
- **WHEN** a YAML's `transforms` block omits any of the six required sub-keys
- **THEN** the application fails to boot with an error naming the missing sub-key

#### Scenario: Non-empty entry rejected
- **WHEN** a YAML declares e.g. `transforms.drop_keys: [user.email]`
- **THEN** the application fails to boot with an error indicating transforms are not yet implemented

### Requirement: Attribute column extraction service

The system SHALL expose `App\Otlp\AttributeColumnExtractor` as a service constructed with a `SchemaDefinition`. The extractor SHALL provide three methods:

- `extractResource(KeyValueDto[] $attrs): array<string, scalar|null>`
- `extractScope(KeyValueDto[] $attrs): array<string, scalar|null>`
- `extractRecord(KeyValueDto[] $attrs): array<string, scalar|null>`

Each method SHALL return a map keyed by promoted column name, where each value is the scalar value of the matching attribute. The input `KeyValueDto[]` SHALL NOT be modified by extraction. Keys not listed in the schema's `promotions` block SHALL be absent from the returned map (the writer fills missing columns with NULL).

When a promotion rule lists multiple semantic-convention keys for the same column (e.g. legacy `deployment.environment` plus current `deployment.environment.name`), the extractor SHALL pick the value of the first listed key that resolves to a non-null scalar.

#### Scenario: Promoted attribute returned in map
- **WHEN** the resource attributes contain `{key: 'service.name', value: stringValue 'checkout'}`
- **AND** the schema's `promotions.resource` maps `service.name` → `resource_service_name`
- **THEN** `extractResource` returns `['resource_service_name' => 'checkout']`

#### Scenario: Unmatched attribute not promoted
- **WHEN** the input contains a key not present in the promotion rules
- **THEN** the returned map does not include that key
- **AND** the input list is unmodified (so the JSON-blob serialiser still sees it)

#### Scenario: Legacy-key fallback
- **WHEN** the schema lists both `deployment.environment.name` and `deployment.environment` for the same column, in that order
- **AND** the input contains only the legacy `deployment.environment` key
- **THEN** the returned map uses the legacy key's value
