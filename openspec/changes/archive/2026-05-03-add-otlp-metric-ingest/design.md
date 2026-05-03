## Context

Logs (`add-otlp-log-ingest`, archived) and traces (`add-otlp-trace-ingest`, archived 2026-05-03) established the multi-signal scaffolding: `OtlpRequestPipeline` (signal-generic HTTP path), `SchemaCatalog`, `AttributeColumnExtractor` factory, `PartitionPathResolver` taking a signal subdir, `ParquetFileWriter` factory taking a signal name, the universal `_schema_*` writer markers, and the `SignalDecoder` + `IngestsSignal` contracts. Adding metrics is a slice along three axes (schema YAML + decoders + ingest service + thin controller), exactly the same shape as adding traces.

The interesting design decisions for metrics are *content* decisions:

1. **What is a row?** Metrics are heterogeneous. A `Metric` proto carries one of five data shapes (`Sum`, `Gauge`, `Histogram`, `ExponentialHistogram`, `Summary`), each containing a list of data-points. We need a row-shape choice that stays queryable but doesn't fan out into five separate Parquet trees.
2. **How are histogram buckets / exemplars / quantiles stored?** flow-php's writer is primitive-only (same constraint as logs/traces), so nested `list<struct>` is out.
3. **How does temporality (`DELTA` vs `CUMULATIVE`) surface?** Critical for downstream consumers — a delta-temporality counter aggregates differently than a cumulative one.
4. **How do exemplars connect back to traces?** Exemplars carry `trace_id`/`span_id` linking each sample to the span that produced it; we want that linkage queryable.

## Goals / Non-Goals

**Goals:**

- `POST /v1/metrics` accepts OTLP/HTTP-JSON and OTLP/HTTP-protobuf with optional gzip, returns OTLP `ExportMetricsServiceResponse` shape (`{}` on success). Same auth, same size limits, same error envelope as logs and traces.
- One Parquet row per **data-point** (not per metric). The `Metric` envelope's name/unit/description/type get denormalized onto every data-point row from that metric.
- Tier-1 universal resource columns byte-for-byte identical to `logs/v1` and `traces/v1` so cross-signal joins are obvious.
- `metric_type` (string) discriminator column carries `SUM` | `GAUGE` | `HISTOGRAM` | `EXPONENTIAL_HISTOGRAM` | `SUMMARY` so filtering by shape is a single predicate.
- `aggregation_temporality_text` (`DELTA` | `CUMULATIVE`) carried per row.
- Histogram buckets stored as JSON-string `buckets_json`. Exponential histograms stored as JSON-string `exponential_histogram_json`. Summary quantiles stored as JSON-string `quantiles_json`. Lossless round-trip via OTLP/HTTP-JSON wire shape.
- Exemplars (zero-or-more per data-point) carried as JSON-string `exemplars_json` preserving `trace_id`/`span_id` for future cross-signal joins.
- Adding a query layer for metrics later should not require schema changes — the JSON blobs are the lossless source of truth.

**Non-Goals:**

- A native Parquet `map<string, string>` or `list<struct>` for attributes / buckets / quantiles. Same primitive-only constraint as logs and traces.
- A separate `metric_data_points/<tenant>/...` Parquet tree per metric. v1 keeps one file per request, one row per data-point.
- Sampling, downsampling, or roll-up at ingest. Reserved in the YAML's `transforms:` block, implemented by the future `add-ingest-transforms` change.
- Aggregation across data-points or across requests. Crashler is a sink, not a TSDB. Roll-ups are a query-layer concern.
- A reverse exemplar→trace lookup index. v1 query path globs partitions; a future change can add an index if this becomes hot.
- Behaviour changes to `/v1/logs` or `/v1/traces`.
- A per-signal authenticator. Existing `IngestTokenAuthenticator` works because the firewall pattern is `^/v1/`.

## Decisions

### D1. One row per data-point; Metric envelope fields denormalized onto each row

**Decision.** Each `NumberDataPoint` / `HistogramDataPoint` / `ExponentialHistogramDataPoint` / `SummaryDataPoint` in the request becomes one Parquet row. The parent `Metric` envelope's `name`, `unit`, `description`, and inferred `metric_type` are copied onto every row produced from that metric. The data-point's own `attributes`, `start_time_unix_nano`, `time_unix_nano`, and value(s) populate the rest of the row. Histograms / exponential histograms / summaries serialise their nested arrays (buckets/positive/negative buckets/quantile values) to JSON-string columns.

**Why.** A row-per-data-point matches how operators query metrics: filter by `metric_name = 'http.server.request.duration'`, then by attributes, then aggregate. Row-per-metric (with a JSON blob of data-points) would force every read to deserialize the blob just to filter. Row-per-bucket would explode cardinality (a 20-bucket histogram with 5 attribute combinations is 100 rows per scrape) and break the "one row per logical record" mental model shared with logs and traces.

**Alternatives considered.**

- *Row per metric, data-points as JSON.* Read-heavy queries pay the deserialisation cost on every filter. Common case is "find this metric across many attribute sets" — that's exactly the case row-per-metric punishes.
- *Row per bucket (histogram explosion).* High cardinality, columnar compression suffers, joins back to the parent histogram are awkward.
- *Separate Parquet trees per metric type.* Five times the file management cost, breaks one-file-per-request, and complicates cross-type queries ("all metrics for service X in this hour").

### D2. `metric_type` is the discriminator column

**Decision.** A required `metric_type` (string) column carries one of `SUM`, `GAUGE`, `HISTOGRAM`, `EXPONENTIAL_HISTOGRAM`, `SUMMARY`. A required int32 `metric_type_code` column carries the same as 0..4 for compact filtering / group-by.

**Why.** Same pattern as `kind` + `kind_text` in traces and `severity_number` + `severity_text` in logs. Numeric column for compact ordering, string column for readable filters. Five values is low cardinality so dictionary encoding makes the string column nearly free. The discriminator shape is also how Prometheus, Tempo, and Mimir model heterogeneous metric types — we're consistent with the ecosystem mental model.

### D3. Scalar value columns: `value_double`, `value_int`, `count`, `sum`

**Decision.** `Sum` / `Gauge` data-points carry a single number — but OTLP allows it as either `as_double` or `as_int`. We expose both as separate optional columns: `value_double` (double, optional) and `value_int` (int64, optional). Exactly one is non-null per Sum/Gauge row. Histogram and ExponentialHistogram data-points carry `count` (int64) and an optional `sum` (double); both get top-level optional columns. Summary carries `count` (int64) + `sum` (double) + a list of quantile values (→ `quantiles_json`).

**Why.** Numeric filtering should be a primitive column predicate. Mixing int and double into a single `value` column would force casts in every query and lose type information. Histogram `count`/`sum` are queried as scalars overwhelmingly often (e.g. computing average via `sum/count`), so they earn their own columns even though they're also inside `buckets_json`.

**Alternatives considered.**

- *Single `value_double` column with int values cast.* Loses int64 precision for large counters (int64 can exceed double's 2^53 mantissa).
- *Variant column / union type.* Parquet doesn't support variant; emulating with a struct breaks the primitive-only constraint.

### D4. Buckets, exemplars, exponential histograms, quantiles as JSON-string columns

**Decision.** Histogram buckets serialise to OTLP/HTTP-JSON wire shape (`{bucketCounts: [...], explicitBounds: [...]}`) into a single `buckets_json` column. ExponentialHistogram serialises its full message (positive + negative bucket arrays, scale, zero count, zero threshold) into `exponential_histogram_json`. Summary quantile values serialise to `quantiles_json` (`[{quantile: 0.5, value: 12.3}, ...]`). Exemplars (per data-point, zero-or-more) serialise to `exemplars_json` preserving `trace_id`/`span_id`/`time_unix_nano`/`value`/`attributes`.

**Why.** Consistent with how `events_json`, `links_json`, and `attributes_json` are stored in the other signals. The OTLP/HTTP-JSON wire shape is a published, stable contract; downstream consumers can read these blobs with any OTLP-aware parser. flow-php's primitive-only constraint rules out native nested types. A future change can lift any of these to first-class rows without breaking existing files (versioned schema).

### D5. Tier-1 universal resource columns identical to logs/v1 and traces/v1

**Decision.** `resource_service_name`, `resource_service_namespace`, `resource_service_version`, `resource_service_instance_id`, `resource_deployment_environment`, `resource_host_name`, `resource_telemetry_sdk_language` — same names, same canonical-then-legacy ordering for `deployment.environment.name` / `deployment.environment`. Same `scope_name`, `scope_version`, `scope_schema_url`.

**Why.** A query joining metrics to logs or traces by `resource_service_name = 'checkout'` should be a single equality predicate. This is also the cheapest correct decision: copy the YAML block.

### D6. Tier-2 record-level promotions are minimal — metric envelope fields, not data-point attributes

**Decision.** Promote three top-level `Metric` envelope fields:

```
metric_name           (string, REQUIRED)  — copied from Metric.name
metric_unit           (string, optional)  — copied from Metric.unit
metric_description    (string, optional)  — copied from Metric.description
```

These are NOT attributes on the data-point — they live on the `Metric` envelope and get denormalized onto every data-point row. We deliberately do NOT promote any data-point attribute keys to top-level columns in v1 because metric attribute conventions are domain-specific (`http.method`, `http.route`, `db.system`, ...) and a one-size-fits-all promotion list would either be too narrow (most users get nothing) or too wide (every domain pollutes every row). All data-point attributes live in `attributes_json`.

**Why.** `metric_name` is the single most-filtered column in any metrics query. Lifting it from "an envelope field denormalized in JSON" to a top-level required column gives single-predicate filters and lets dictionary encoding compress the file dramatically (a typical scrape has hundreds of data-points across a dozen distinct `metric_name` values). `unit` and `description` come along for the ride because they're also envelope-level.

**Alternatives considered.**

- *Promote HTTP/db/messaging keys like traces does.* Plausible but risky: the moment we ship `http_method` we're committing to that name for all time, even though metric semconv is more in-flux than trace semconv. Defer.
- *Skip `metric_unit` and `metric_description`.* Unit is queried often enough ("show me everything in `ms`") to earn a column. Description is a one-time copy and helps query-layer UX.

### D7. Aggregation temporality + monotonicity carried as columns

**Decision.** Optional `aggregation_temporality_text` column (`DELTA` | `CUMULATIVE` | `UNSPECIFIED`) plus int32 `aggregation_temporality` (0 = UNSPECIFIED, 1 = DELTA, 2 = CUMULATIVE). Optional `is_monotonic` (boolean). Both are populated only for `SUM` and `HISTOGRAM` / `EXPONENTIAL_HISTOGRAM` rows (the only types where they're defined); null for `GAUGE` and `SUMMARY`.

**Why.** A delta-temporality counter aggregates by sum across windows; a cumulative one aggregates by max-minus-min. Getting this wrong silently produces nonsense numbers, so the column has to be there even though it's null for two of the five types. Dictionary encoding makes the cost trivial.

### D8. Exemplar trace/span IDs preserved in hex inside `exemplars_json`

**Decision.** `exemplars_json` stores OTLP/HTTP-JSON wire shape; `traceId` and `spanId` inside it are emitted as lowercase hex (matching how traces stores them) so a future query-layer join `exemplars.traceId = spans.trace_id_hex` is a string equality.

**Why.** OTLP's proto3 emits these as base64 by default; hex matches our trace-id storage convention and avoids a per-query base64 decode.

### D9. Empty data-point arrays produce no rows

**Decision.** A `Metric` whose data-points list is empty produces zero rows (the metric envelope is dropped). A request whose every metric has empty data-points produces zero rows total → no Parquet file written, just like an empty logs/traces request.

**Why.** Writing rows with all data-point fields null would fail required-column constraints (`time_unix_nano` is REQUIRED). Empty-metric envelopes carry no information; dropping them is correct.

### D10. Same atomic write, same partition layout, same factory wiring

**Decision.** `<storage-root>/metrics/<tenant_slug>/date=YYYY-MM-DD/hour=HH/part-<ulid>.parquet`. Same `.tmp + rename`, same default GZIP, same MockClock + StubFilenameGenerator hooks for tests. `services.yaml` gets `crashler.parquet_writer.metrics` and `crashler.attribute_extractor.metrics` ids parallel to traces. `MetricsIngestService` binds them via explicit `$writer` + `$extractor` arguments.

**Why.** No surprises. Operators run one Crashler; they shouldn't have to learn three different file-management conventions.

## Risks / Trade-offs

- **[Risk]** OTLP Summary type is deprecated by OpenTelemetry but still appears in some client libraries. → **Mitigation:** Decode and store it (one row per `SummaryDataPoint`) but document in README that operators should prefer Histogram / ExponentialHistogram. The schema reserves the columns regardless of incoming traffic.
- **[Risk]** ExponentialHistogram messages can be large (positive + negative bucket arrays plus zero count plus scale). A pathological request could push past `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES`. → **Mitigation:** The existing 16 MiB streaming cap stops the decompression mid-flight; the operator hits 413 and learns to chunk.
- **[Risk]** Metric attribute keys are still in semconv flux (the "http.request.method" → "http.method" oscillation is recent memory). Promoting any data-point attribute to a top-level column would lock us into that name. → **Mitigation:** Deliberate non-decision in v1 (D6). All data-point attributes stay in `attributes_json`. Promote in `metrics/v2` once semconv stabilises.
- **[Risk]** flow-php's `ROW_GROUP_SIZE_BYTES` default of 32 MiB might be tight for a histogram-heavy request: a Prometheus-style scrape with 50 metrics × 20 buckets × 5 attribute sets = 5000 rows, each carrying a JSON blob. → **Mitigation:** Same as logs/traces — the writer flushes multiple row groups per request. We measure on first production traffic.
- **[Trade-off]** Storing `value_double` and `value_int` as two separate optional columns instead of one `value` variant. **Cost:** every Sum/Gauge row has one wasted optional. **Benefit:** typed queries, no precision loss. Optional columns in Parquet cost a definition-level bit per row, not a full value, so the cost is genuinely small.
- **[Trade-off]** Storing histogram detail as `buckets_json` (string) instead of a parallel `metric_buckets/...` Parquet tree. **Cost:** every histogram-bucket query needs `json_extract` in DuckDB. **Benefit:** one writer per request, one file per request, schema versioning trivially evolves bucket semantics. Re-evaluate if bucket-level queries become hot.

## Migration Plan

- Additive: no migration needed. Existing tenants keep their `logs/<slug>/` and `traces/<slug>/` trees unchanged. The first metric write creates `metrics/<slug>/`.
- No env-flag dance. No schema-breaking purge.
- Production deploy is `dep deploy stage=production`; release N+1 picks up the new schema YAML, the new controller, and the new services.yaml wiring atomically.
- Rollback: redeploy the previous release tag. Any metric files written in the meantime stay on disk under `metrics/<slug>/` and become re-readable when the code rolls forward again. No data loss; the rolled-back release simply ignores the metrics tree.

## Open Questions

- **Do we want to promote `metric.unit` to a typed column or keep it as the OpenTelemetry-recommended UCUM string?** Decision: keep as string. UCUM is a published spec; consumers parse it themselves if they need structured units. Adds no cost over what we'd do anyway.
- **Should `exemplars_json` default to `'[]'` or NULL when empty?** Decision: `'[]'` for symmetry with `events_json` / `links_json` in traces. Querying for "data-points with exemplars" becomes `exemplars_json != '[]'`.
- **Does `metric_type_code` belong in the schema, or just the text column?** Decision: include both, mirroring `kind` + `kind_text` in traces. Numeric is cheap; group-by-type queries benefit.
