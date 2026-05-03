## Why

Crashler ships OTLP logs (`/v1/logs`) and traces (`/v1/traces`) but rejects metrics, leaving operators reliant on a separate sink for the third OTLP signal. Adding metrics on top of the established multi-signal scaffolding (HTTP pipeline, schema catalog, attribute extractor, ingest contracts) closes the gap and makes Crashler a complete OTLP receiver — same auth, same tenancy, same on-disk shape, queryable from the same DuckDB tree.

## What Changes

- New `POST /v1/metrics` endpoint accepting OTLP/HTTP-JSON and OTLP/HTTP-protobuf bodies (with optional gzip), gated by the existing bearer-token auth.
- New `metrics/v1` schema: **one row per metric data-point**, not per metric. The schema carries a `metric_type` discriminator (`SUM` | `GAUGE` | `HISTOGRAM` | `EXPONENTIAL_HISTOGRAM` | `SUMMARY`) plus type-specific columns or JSON-string blobs (`buckets_json`, `quantiles_json`, `exemplars_json`).
- Tier-1 resource promotions reuse the same column names as `logs/v1` and `traces/v1` for cross-signal joins (`resource_service_name`, `resource_deployment_environment`, etc.).
- Tier-2 record-level promotions cover the metric-specific stable semconv keys: `metric.name`, `metric.unit`, `metric.description` (top-level metric fields, not attributes — promoted from the `Metric` envelope).
- Aggregation temporality is preserved as a column (`aggregation_temporality_text`: `DELTA` | `CUMULATIVE`) so cumulative-vs-delta downstream consumers do the right thing.
- Per-data-point `start_time_unix_nano` and `time_unix_nano` carried verbatim; `attributes_json` carries each data-point's per-point attributes; `metric_attributes_json` carries the parent `Metric` fields the row was flattened from for round-trip fidelity.
- Histograms: bucket boundaries + counts emitted as `buckets_json` (JSON-string column, not native `list<struct>`); single `count` and `sum` columns for the common scalar reductions.
- Exponential histograms: the same JSON-blob approach (`exponential_histogram_json`) since flow-php's primitive-only constraint applies.
- Exemplars (where present) live in `exemplars_json` and preserve `trace_id`/`span_id` linkage so future query-layer joins to the traces tree are possible.
- File layout mirrors logs and traces: `<storage-root>/metrics/<tenant_slug>/date=YYYY-MM-DD/hour=HH/part-<ulid>.parquet`. Same atomic `.tmp + rename`. Same default GZIP. Same writer pipeline.
- README updated with a metrics DuckDB query example.
- No tenant-config or auth changes.

## Capabilities

### New Capabilities
- `metric-ingest`: Defines the `POST /v1/metrics` HTTP API — content-type dispatch, gzip, size limits, auth, error mapping, OTLP-shaped success response (`ExportMetricsServiceResponse` → `{}`).
- `metric-storage`: Defines the on-disk Parquet layout for metrics — partition path, atomic commit, `metrics/v1` row shape (data-point as row), the type discriminator, JSON-string columns for histogram/exponential-histogram/summary detail, and the universal `_schema_*` writer markers.

### Modified Capabilities
- (none — `schema-catalog`, `tenants`, `log-ingest`, `log-storage`, `trace-ingest`, `trace-storage` are all unchanged)

## Impact

- New code: `App\Otlp\MetricsJsonDecoder`, `App\Otlp\MetricsProtobufDecoder`, `App\Otlp\Dto\MetricsDto*` value objects, `App\Metrics\MetricsIngestService`, `App\Otlp\HistogramJsonEncoder` / `ExponentialHistogramJsonEncoder` / `SummaryJsonEncoder` / `ExemplarJsonEncoder`, `App\Controller\OtlpMetricsController`.
- New config: `config/schemas/metrics/v1.yaml`.
- Wiring: `services.yaml` gains a third `crashler.parquet_writer.metrics` and `crashler.attribute_extractor.metrics` pair, parallel to the traces pattern.
- Tests: unit (DTOs, decoders, encoders, ingest service), component (real ParquetFileWriter round-trip), functional (controller via zenstruck/browser; cross-signal isolation extended to three signals).
- Dependencies: `open-telemetry/gen-otlp-protobuf` already provides the Metrics proto types — no new composer requires.
- Storage: existing tenants gain a `metrics/<slug>/` subtree the first time a metric request lands. No migration; `metrics/` is independent of `logs/` and `traces/`.
- Production deploy: additive, no env flag, no schema-breaking purge needed.
