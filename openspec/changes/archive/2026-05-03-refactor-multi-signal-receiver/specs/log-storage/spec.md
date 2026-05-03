## MODIFIED Requirements

### Requirement: Parquet schema and types

Each Parquet file SHALL be written using the column layout defined by the `logs/v1` schema in the schema catalog (`config/schemas/logs/v1.yaml`). The full row shape produced by the writer is:

| column                              | type   | repetition | source                                                |
| ----------------------------------- | ------ | ---------- | ----------------------------------------------------- |
| `time_unix_nano`                    | int64  | REQUIRED   | LogRecord.time_unix_nano                               |
| `resource_attributes_json`          | string | REQUIRED   | full ResourceLogs.resource.attributes as JSON         |
| `attributes_json`                   | string | REQUIRED   | full LogRecord.attributes as JSON                     |
| `resource_service_name`             | string | optional   | promoted: `service.name`                              |
| `resource_service_namespace`        | string | optional   | promoted: `service.namespace`                         |
| `resource_service_version`          | string | optional   | promoted: `service.version`                           |
| `resource_service_instance_id`      | string | optional   | promoted: `service.instance.id`                       |
| `resource_deployment_environment`   | string | optional   | promoted: `deployment.environment.name` or legacy `deployment.environment` |
| `resource_host_name`                | string | optional   | promoted: `host.name`                                 |
| `resource_telemetry_sdk_language`   | string | optional   | promoted: `telemetry.sdk.language`                    |
| `scope_name`                        | string | optional   | ScopeLogs.scope.name                                  |
| `scope_version`                     | string | optional   | ScopeLogs.scope.version                               |
| `scope_schema_url`                  | string | optional   | promoted from scope-level: `schema_url`               |
| `observed_time_unix_nano`           | int64  | optional   | LogRecord.observed_time_unix_nano                     |
| `severity_number`                   | int32  | optional   | LogRecord.severity_number                             |
| `severity_text`                     | string | optional   | LogRecord.severity_text                               |
| `body_json`                         | string | optional   | LogRecord.body (AnyValue serialised as JSON wire form) |
| `event_name`                        | string | optional   | promoted record-level: `event.name`                   |
| `exception_type`                    | string | optional   | promoted record-level: `exception.type`               |
| `exception_message`                 | string | optional   | promoted record-level: `exception.message`            |
| `trace_id_hex`                      | string | optional   | LogRecord.trace_id (lowercase 32-char hex)            |
| `span_id_hex`                       | string | optional   | LogRecord.span_id (lowercase 16-char hex)             |
| `flags`                             | int32  | optional   | LogRecord.flags                                       |

In addition, every row carries two universal infrastructure columns appended by the writer (per the `schema-catalog` capability's "Universal _schema_version and _schema_id columns" Requirement): `_schema_version` (int32 REQUIRED, value `1`) and `_schema_id` (string REQUIRED, value `logs/v1`).

Promoted-column values are *shadows*: every promoted attribute remains in the corresponding `resource_attributes_json` or `attributes_json` blob unchanged. Renaming or removing a promoted column never loses data because the JSON blob is the lossless source of truth.

All Parquet column types SHALL be primitive in this version; native `map<string, string>` is explicitly out of scope to preserve AnyValue fidelity.

#### Scenario: Schema columns present
- **WHEN** any Parquet file produced by the handler is opened by a reader
- **THEN** all columns above are present with the documented types and repetitions
- **AND** the file's row schema also exposes `_schema_version` (int32 REQUIRED) and `_schema_id` (string REQUIRED)

#### Scenario: Resource attributes denormalised onto every row, plus promoted columns
- **WHEN** an OTLP request contains one ResourceLogs block with N LogRecords whose resource attributes include `service.name=checkout` and `host.name=node-1`
- **THEN** the resulting Parquet file contains N rows
- **AND** every row has the same `resource_attributes_json` (the full JSON of the resource attributes)
- **AND** every row has `resource_service_name = 'checkout'`
- **AND** every row has `resource_host_name = 'node-1'`
- **AND** the values still appear inside `resource_attributes_json` (shadow promotion, not move)

#### Scenario: Legacy deployment.environment key promoted
- **WHEN** a request's resource attributes contain only the legacy `deployment.environment` key (without `.name`)
- **THEN** every row has `resource_deployment_environment` set to that value
- **AND** the value still appears inside `resource_attributes_json`

#### Scenario: Record-level promotions extracted
- **WHEN** a LogRecord's attributes contain `event.name = 'http.server.request'` and `exception.type = 'RuntimeException'`
- **THEN** the corresponding row has `event_name = 'http.server.request'` and `exception_type = 'RuntimeException'`
- **AND** both values still appear inside `attributes_json`

#### Scenario: Attributes are JSON strings
- **WHEN** an attribute set is `{"http.status_code": 500, "user.id": "u-42"}`
- **THEN** the corresponding Parquet column value for that row is a string containing valid JSON equivalent to the input

#### Scenario: Universal _schema_id reflects the schema used
- **WHEN** any Parquet file produced for the logs signal is opened
- **THEN** every row carries `_schema_version = 1` and `_schema_id = 'logs/v1'`

#### Scenario: Schema YAML is the source of truth
- **WHEN** the operator edits `config/schemas/logs/v1.yaml` to declare an additional optional column and pushes a release
- **THEN** subsequent Parquet files include the new column (NULL where unpopulated)
- **AND** existing Parquet files on disk remain readable; their rows have no value for the new column (DuckDB returns NULL)
