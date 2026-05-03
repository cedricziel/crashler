## ADDED Requirements

### Requirement: Storage root and signal subdirectory

The system SHALL write metric Parquet files under `<storage-root>/metrics/`, where `<storage-root>` is the same directory used for the logs and traces signals (resolved from `APP_SHARE_DIR` with a default of `<project>/var/share`). A separate `metrics/` directory SHALL coexist with the existing `logs/` and `traces/` directories under the same root; tenant separation continues to be a security boundary while signal separation is operational.

#### Scenario: Metrics, traces, and logs share storage root
- **WHEN** `/v1/logs`, `/v1/traces`, and `/v1/metrics` accept requests for tenant `acme`
- **THEN** the resulting files land under `<storage-root>/logs/acme/…`, `<storage-root>/traces/acme/…`, and `<storage-root>/metrics/acme/…` respectively
- **AND** no writer's files appear in another's directory tree

### Requirement: On-disk layout

For every accepted request, the system SHALL write exactly one Parquet file at `<storage-root>/metrics/<tenant_slug>/date=<YYYY-MM-DD>/hour=<HH>/part-<ulid>.parquet`. The `<tenant_slug>` segment SHALL be the slug of the authenticated tenant. `<YYYY-MM-DD>` and `<HH>` SHALL be derived from the request's wall-clock arrival time interpreted as UTC. `<ulid>` SHALL be a Crockford-base32 ULID generated at file-creation time so directory listings sort by creation order. Parent directories SHALL be created with mode 0750 if they do not already exist.

#### Scenario: Tenant directory used
- **WHEN** a request is accepted for tenant `acme`
- **THEN** the resulting Parquet file's path begins with `<storage-root>/metrics/acme/`

#### Scenario: Hive partition layout from ingest time
- **WHEN** a request arrives at 2026-05-03 14:37 UTC
- **THEN** the resulting Parquet file lives at `…/<slug>/date=2026-05-03/hour=14/part-<ulid>.parquet`
- **AND** this is true regardless of any data-point's own `time_unix_nano` value

#### Scenario: One file per accepted request
- **WHEN** a request contains data-points whose `time_unix_nano` values span multiple event-time hours
- **THEN** all data-points are still written to a single Parquet file selected by ingest time
- **AND** each row's `start_time_unix_nano` and `time_unix_nano` columns reflect each data-point's actual event times

### Requirement: Atomic file commit via .tmp + rename

The system SHALL write each Parquet file to `<final-path>.tmp`, close the writer, fsync the underlying file descriptor, and `rename()` the file to `<final-path>`. The HTTP 200 response SHALL only be sent after the rename returns successfully. If any step fails, the `.tmp` file SHALL be unlinked before the request returns 5xx.

#### Scenario: Reader never observes partial file
- **WHEN** the handler is mid-write
- **THEN** no file at the final destination path is visible to other processes until the writer is closed and renamed

#### Scenario: Failed write leaves no orphan
- **WHEN** the Parquet write or rename fails for any reason
- **THEN** the request returns 5xx
- **AND** no `.tmp` file remains on disk for this request

### Requirement: Parquet schema and types

Each Parquet file SHALL be written using the column layout defined by the `metrics/v1` schema in the schema catalog (`config/schemas/metrics/v1.yaml`). Each row represents exactly one OTLP data-point; the parent `Metric` envelope's `name`, `unit`, `description`, `metric_type`, `aggregation_temporality`, and `is_monotonic` fields are denormalized onto every row produced from that metric. The full row shape produced by the writer is:

| column                              | type   | repetition | source                                                                                       |
| ----------------------------------- | ------ | ---------- | -------------------------------------------------------------------------------------------- |
| `metric_name`                       | string | REQUIRED   | Metric.name                                                                                  |
| `metric_type`                       | string | REQUIRED   | derived: `SUM` \| `GAUGE` \| `HISTOGRAM` \| `EXPONENTIAL_HISTOGRAM` \| `SUMMARY`              |
| `metric_type_code`                  | int32  | REQUIRED   | enum: 0=SUM, 1=GAUGE, 2=HISTOGRAM, 3=EXPONENTIAL_HISTOGRAM, 4=SUMMARY                         |
| `time_unix_nano`                    | int64  | REQUIRED   | DataPoint.time_unix_nano                                                                     |
| `start_time_unix_nano`              | int64  | optional   | DataPoint.start_time_unix_nano                                                               |
| `metric_unit`                       | string | optional   | Metric.unit                                                                                  |
| `metric_description`                | string | optional   | Metric.description                                                                           |
| `aggregation_temporality`           | int32  | optional   | 0=UNSPECIFIED, 1=DELTA, 2=CUMULATIVE (Sum / Histogram / ExponentialHistogram only)            |
| `aggregation_temporality_text`      | string | optional   | `UNSPECIFIED` \| `DELTA` \| `CUMULATIVE`                                                      |
| `is_monotonic`                      | boolean | optional  | Sum.is_monotonic (Sum only)                                                                  |
| `value_double`                      | double | optional   | NumberDataPoint.as_double (Sum / Gauge only)                                                 |
| `value_int`                         | int64  | optional   | NumberDataPoint.as_int (Sum / Gauge only)                                                    |
| `count`                             | int64  | optional   | Histogram.count / ExponentialHistogram.count / Summary.count                                 |
| `sum`                               | double | optional   | Histogram.sum / ExponentialHistogram.sum / Summary.sum                                       |
| `min`                               | double | optional   | Histogram.min / ExponentialHistogram.min                                                     |
| `max`                               | double | optional   | Histogram.max / ExponentialHistogram.max                                                     |
| `buckets_json`                      | string | optional   | Histogram bucket structure as OTLP/HTTP-JSON: `{bucketCounts, explicitBounds}`                |
| `exponential_histogram_json`        | string | optional   | full ExponentialHistogramDataPoint message as OTLP/HTTP-JSON (positive/negative buckets, scale, zero count, zero threshold) |
| `quantiles_json`                    | string | optional   | SummaryDataPoint.quantile_values as OTLP/HTTP-JSON list                                      |
| `exemplars_json`                    | string | REQUIRED   | DataPoint.exemplars as OTLP/HTTP-JSON list (defaults to `[]`); traceId/spanId emitted as lowercase hex |
| `attributes_json`                   | string | REQUIRED   | full DataPoint.attributes as JSON                                                            |
| `metric_attributes_json`            | string | REQUIRED   | full Metric envelope as JSON for round-trip fidelity (excluding the data-points list)        |
| `resource_attributes_json`          | string | REQUIRED   | full ResourceMetrics.resource.attributes as JSON                                             |
| `flags`                             | int32  | optional   | DataPoint.flags                                                                              |
| `resource_service_name`             | string | optional   | promoted: `service.name`                                                                     |
| `resource_service_namespace`        | string | optional   | promoted: `service.namespace`                                                                |
| `resource_service_version`          | string | optional   | promoted: `service.version`                                                                  |
| `resource_service_instance_id`      | string | optional   | promoted: `service.instance.id`                                                              |
| `resource_deployment_environment`   | string | optional   | promoted: `deployment.environment.name` or legacy `deployment.environment`                    |
| `resource_host_name`                | string | optional   | promoted: `host.name`                                                                        |
| `resource_telemetry_sdk_language`   | string | optional   | promoted: `telemetry.sdk.language`                                                           |
| `scope_name`                        | string | optional   | ScopeMetrics.scope.name                                                                      |
| `scope_version`                     | string | optional   | ScopeMetrics.scope.version                                                                   |
| `scope_schema_url`                  | string | optional   | ScopeMetrics.schema_url                                                                      |

In addition, every row carries the universal `_schema_version` (int32 REQUIRED, value `1`) and `_schema_id` (string REQUIRED, value `metrics/v1`) columns appended by the writer per the `schema-catalog` capability.

`metric_name`, `metric_unit`, and `metric_description` are *shadows*: their values also appear inside `metric_attributes_json` for lossless round-trip. Histogram / ExponentialHistogram / Summary detail JSON blobs (`buckets_json`, `exponential_histogram_json`, `quantiles_json`) are populated only for rows whose `metric_type` matches; otherwise they are NULL. `value_double` / `value_int` are populated only for `SUM` / `GAUGE` rows; `count` / `sum` / `min` / `max` are populated only for `HISTOGRAM` / `EXPONENTIAL_HISTOGRAM` / `SUMMARY` rows as applicable.

All Parquet column types SHALL be primitive in this version; native `map<string, string>` and `list<struct>` are explicitly out of scope.

#### Scenario: Schema columns present
- **WHEN** any Parquet file produced by the metric writer is opened by a reader
- **THEN** all columns above are present with the documented types and repetitions
- **AND** the file's row schema also exposes `_schema_version` (int32 REQUIRED) and `_schema_id` (string REQUIRED)

#### Scenario: Resource attributes denormalised onto every row, plus promoted columns
- **WHEN** a request contains one ResourceMetrics block with N data-points whose resource attributes include `service.name=checkout` and `host.name=node-1`
- **THEN** the resulting Parquet file contains N rows
- **AND** every row has the same `resource_attributes_json` (the full JSON of the resource attributes)
- **AND** every row has `resource_service_name = 'checkout'` and `resource_host_name = 'node-1'`
- **AND** the values still appear inside `resource_attributes_json` (shadow promotion, not move)

#### Scenario: Metric envelope fields denormalized onto each data-point row
- **WHEN** a Metric named `http.server.request.duration` with unit `ms` and 5 HistogramDataPoints is decoded
- **THEN** all 5 resulting rows share `metric_name = 'http.server.request.duration'` and `metric_unit = 'ms'`
- **AND** each row's `metric_type = 'HISTOGRAM'` and `metric_type_code = 2`

#### Scenario: Sum data-point produces value_int xor value_double
- **WHEN** a Sum data-point carries `as_int = 100`
- **THEN** the row's `value_int = 100` and `value_double IS NULL`
- **WHEN** a Sum data-point carries `as_double = 1.5`
- **THEN** the row's `value_double = 1.5` and `value_int IS NULL`

#### Scenario: Histogram count, sum, and buckets_json populated together
- **WHEN** a HistogramDataPoint carries `count=42`, `sum=123.4`, and bucket structure
- **THEN** the row's `count = 42`, `sum = 123.4`
- **AND** `buckets_json` contains a JSON object with `bucketCounts` and `explicitBounds` arrays
- **AND** the row's `value_double` and `value_int` columns are NULL

#### Scenario: ExponentialHistogram detail in single JSON blob
- **WHEN** an ExponentialHistogramDataPoint carries scale, zero count, and positive/negative bucket arrays
- **THEN** the row's `exponential_histogram_json` contains the full proto sub-message in OTLP/HTTP-JSON form
- **AND** `count` / `sum` / `min` / `max` columns are also populated for direct-scalar queries

#### Scenario: Summary quantile values stored as JSON list
- **WHEN** a SummaryDataPoint carries 3 quantile values
- **THEN** the row's `quantiles_json` is a JSON array of 3 `{quantile, value}` objects
- **AND** `count` and `sum` are populated for direct-scalar queries

#### Scenario: Aggregation temporality preserved per row
- **WHEN** a Sum metric has `aggregation_temporality = AGGREGATION_TEMPORALITY_DELTA` (proto enum 1)
- **THEN** every Sum row from that metric carries `aggregation_temporality = 1` and `aggregation_temporality_text = 'DELTA'`

#### Scenario: Gauge data-point leaves temporality columns NULL
- **WHEN** a Gauge data-point is written
- **THEN** `aggregation_temporality`, `aggregation_temporality_text`, and `is_monotonic` are all NULL

#### Scenario: Exemplars carried as JSON list
- **WHEN** a data-point has 2 exemplars
- **THEN** the row's `exemplars_json` is a JSON array of length 2
- **AND** when the data-point has no exemplars, the column holds `[]` (not NULL)
- **AND** any `traceId` and `spanId` inside `exemplars_json` are emitted as lowercase hex strings matching the convention used by `traces/v1.trace_id_hex`

#### Scenario: Universal _schema_id reflects the schema used
- **WHEN** any Parquet file produced for the metrics signal is opened
- **THEN** every row carries `_schema_version = 1` and `_schema_id = 'metrics/v1'`

#### Scenario: Empty data-points produce no rows
- **WHEN** a Metric envelope decodes successfully but its data-points list is empty
- **THEN** zero rows for that metric are written
- **AND** if the entire request has only empty-data-point metrics, no Parquet file is written at all

### Requirement: Compression configuration

The metric writer SHALL use the same compression codec configured for the rest of Crashler (`CRASHLER_PARQUET_COMPRESSION` environment variable). The default is `GZIP`. If the configured codec requires a PHP extension that is not loaded, the application SHALL fail fast at boot rather than at write time.

#### Scenario: Default GZIP compression
- **WHEN** `CRASHLER_PARQUET_COMPRESSION` is unset
- **THEN** metric Parquet files are written with GZIP compression

### Requirement: Memory-bounded row groups

The metric writer SHALL configure flow-php's `ROW_GROUP_SIZE_BYTES` option to a value bounded such that a single request's data-points (typically a few hundred per OTel scrape) do not exceed the worker's PHP `memory_limit`. The default SHALL be 32 MiB, matching the logs and traces signals.

#### Scenario: Row-group size respected
- **WHEN** a request contains data-points whose serialized form exceeds the row-group size
- **THEN** the writer flushes multiple row groups during the request
- **AND** the handler's peak memory usage stays bounded

### Requirement: No background workers or write-ahead log for metrics

The system SHALL NOT include a write-ahead log table, a flush worker, a console command for flushing, or any other process that runs outside of the HTTP request lifecycle for metric ingest in this change. All metric Parquet write activity SHALL occur synchronously in the request that produced the data.

#### Scenario: No flush command exists for metrics
- **WHEN** an operator lists registered Symfony console commands
- **THEN** no command for flushing, draining, or compacting metric Parquet files is registered
