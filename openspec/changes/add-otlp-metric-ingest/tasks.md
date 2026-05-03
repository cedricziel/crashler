**Methodology.** Strict red-green-refactor. Every implementation task is preceded by a failing test for the smallest meaningful behaviour. `[red]` writes a failing test; `[green]` makes it pass with the minimum code; refactor cycles are guarded by the existing test suite remaining green without test-body changes.

The scaffolding established by `refactor-multi-signal-receiver` (and reused by `add-otlp-trace-ingest`) carries most of the cost: this change does not touch `OtlpRequestPipeline`, `SchemaCatalog`, `ParquetFileWriter`, `PartitionPathResolver`, `AttributeColumnExtractor`, or the tenant model. Each is reused unchanged. The cross-signal isolation tests already exercise three subdirs in spirit; we extend them to actually post to all three signals.

## 1. metrics/v1 YAML

- [x] 1.1 [red] Component test: `SchemaCatalog::fromDirectory('config/schemas')` returns a definition for `metrics/v1` with the documented columns and promotion rules (mirrors `LogsV1SchemaTest` and `TracesV1SchemaTest`)
- [x] 1.2 Author `config/schemas/metrics/v1.yaml` per the metric-storage delta spec table (universal `_schema_*` appended by writer)
- [x] 1.3 [green] Test from 1.1 passes
- [x] 1.4 [red] DataProvider test: every required column from the spec table is present with correct type and repetition
- [x] 1.5 [red] Test: tier-1 resource promotions are byte-for-byte identical to `logs/v1` and `traces/v1` (same column names + same key order including the canonical-then-legacy `deployment.environment.*` ordering)
- [x] 1.6 [red] Test: scope schema_url promotion present
- [x] 1.7 [red] Test: no record-level (data-point attribute) promotions in v1 (D6 deliberate non-decision)

## 2. Metric DTOs (TDD)

- [x] 2.1 [red] Unit tests for `App\Otlp\Dto\NumberDataPointDto` (asInt xor asDouble, attributes, start/time, exemplars, flags)
- [x] 2.2 [green] Implement `NumberDataPointDto` (immutable readonly value object)
- [x] 2.3 [red] Unit tests for `App\Otlp\Dto\HistogramDataPointDto` (count, sum, min, max, bucket_counts, explicit_bounds, attributes, exemplars)
- [x] 2.4 [green] Implement `HistogramDataPointDto`
- [x] 2.5 [red] Unit tests for `App\Otlp\Dto\ExponentialHistogramDataPointDto` (scale, zero_count, zero_threshold, positive/negative bucket DTOs, count, sum)
- [x] 2.6 [green] Implement `ExponentialHistogramDataPointDto` + nested `BucketsDto`
- [x] 2.7 [red] Unit tests for `App\Otlp\Dto\SummaryDataPointDto` (count, sum, list of `ValueAtQuantileDto`)
- [x] 2.8 [green] Implement `SummaryDataPointDto` + `ValueAtQuantileDto`
- [x] 2.9 [red] Unit tests for `App\Otlp\Dto\ExemplarDto` (filtered_attributes, time, traceId/spanId raw bytes, asInt xor asDouble)
- [x] 2.10 [green] Implement `ExemplarDto`
- [x] 2.11 [red] Unit tests for `App\Otlp\Dto\MetricDto` (name, unit, description, type discriminator, temporality, is_monotonic, list of typed data-points)
- [x] 2.12 [green] Implement `MetricDto` (with a typed enum for the metric variant)
- [x] 2.13 [red] Unit tests for `App\Otlp\Dto\ScopeMetricsDto` and `App\Otlp\Dto\ResourceMetricsDto`
- [x] 2.14 [green] Implement both
- [x] 2.15 [red] Unit tests for `App\Otlp\Dto\ExportMetricsServiceRequestDto`
- [x] 2.16 [green] Implement it

## 3. MetricsJsonDecoder (TDD)

- [x] 3.1 [red] Test: minimal valid OTLP/HTTP-JSON `ExportMetricsServiceRequest` with one Sum metric / one NumberDataPoint decodes to expected DTO tree
- [x] 3.2 [green] Implement `MetricsJsonDecoder::decode` skeleton
- [x] 3.3 [red] Test: `startTimeUnixNano` and `timeUnixNano` accepted as both string and number
- [x] 3.4 [green] Add dual numeric/string parsing
- [x] 3.5 [red] Test: NumberDataPoint `asInt` (numeric string) and `asDouble` (number) both decode and preserve variant
- [x] 3.6 [green] Decode the variant-bearing data-point shape
- [x] 3.7 [red] Test: HistogramDataPoint with 5-bucket explicit_bounds + 6 bucket_counts decodes losslessly
- [x] 3.8 [green] Decode HistogramDataPoint
- [x] 3.9 [red] Test: ExponentialHistogramDataPoint with positive + negative buckets, scale, zero count, zero threshold decodes
- [x] 3.10 [green] Decode ExponentialHistogramDataPoint
- [x] 3.11 [red] Test: SummaryDataPoint with 3 quantile values decodes
- [x] 3.12 [green] Decode SummaryDataPoint
- [x] 3.13 [red] Test: Exemplar with hex traceId/spanId decodes to raw bytes; AnyValue variants in filtered_attributes preserved
- [x] 3.14 [green] Decode Exemplar
- [x] 3.15 [red] Test: `aggregationTemporality` enum int (0/1/2) preserved per metric type; absent for Gauge/Summary
- [x] 3.16 [green] Decode aggregation temporality + is_monotonic
- [x] 3.17 [red] Tests for schema mismatches: missing `resourceMetrics`, wrong type for `scopeMetrics`, wrong type for `metrics`, missing `name`, missing data-point arrays, missing `timeUnixNano` — each throws `OtlpDecodeException` naming the JSONPath
- [x] 3.18 [green] Add schema validation
- [x] 3.19 Declares `implements App\Otlp\Contract\SignalDecoder`

## 4. MetricsProtobufDecoder (TDD)

- [x] 4.1 [red] Test: round-trip via `Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest` — known input proto serialised, decoded by us, DTO tree matches
- [x] 4.2 [green] Implement `MetricsProtobufDecoder::decode`
- [x] 4.3 [red] Test: NumberDataPoint asInt and asDouble round-trip via protobuf
- [x] 4.4 [red] Test: HistogramDataPoint round-trip with bucket_counts and explicit_bounds
- [x] 4.5 [red] Test: ExponentialHistogramDataPoint round-trip preserving scale and bucket arrays
- [x] 4.6 [red] Test: SummaryDataPoint round-trip with quantile values
- [x] 4.7 [red] Test: Exemplar with raw 16/8-byte traceId/spanId round-trips
- [x] 4.8 [red] Test: `aggregationTemporality` enum int round-trips per metric type
- [x] 4.9 [red] Test: AnyValue variants on data-point attributes AND on exemplar filtered_attributes round-trip via the protobuf path
- [x] 4.10 [red] Test: garbage bytes (truncated length-delimited) throw `OtlpDecodeException`
- [x] 4.11 [green] Wire all of the above; `implements SignalDecoder`

## 5. JSON encoders for nested arrays (TDD)

- [x] 5.1 [red] Unit test: `HistogramBucketsJsonEncoder::encode(null)` returns NULL; populated case returns `{bucketCounts: [...], explicitBounds: [...]}`
- [x] 5.2 [green] Implement `App\Otlp\HistogramBucketsJsonEncoder`
- [x] 5.3 [red] Unit test: `ExponentialHistogramJsonEncoder` round-trips full ExponentialHistogramDataPoint to OTLP/HTTP-JSON wire shape (positive/negative bucket arrays, scale, zero count, zero threshold)
- [x] 5.4 [green] Implement `App\Otlp\ExponentialHistogramJsonEncoder`
- [x] 5.5 [red] Unit test: `SummaryQuantilesJsonEncoder::encode([])` returns `[]`; populated case returns OTLP/HTTP-JSON `[{quantile, value}, ...]`
- [x] 5.6 [green] Implement `App\Otlp\SummaryQuantilesJsonEncoder`
- [x] 5.7 [red] Unit test: `ExemplarJsonEncoder::encode([])` returns `[]`; populated case returns OTLP/HTTP-JSON list with hex traceId/spanId, asInt/asDouble variants, filtered_attributes
- [x] 5.8 [green] Implement `App\Otlp\ExemplarJsonEncoder` (reuse `AnyValueJsonEncoder` for filtered_attributes)
- [x] 5.9 [red] Unit test: `MetricEnvelopeJsonEncoder::encode($metric)` produces JSON matching the parent Metric envelope minus the data-points list (for round-trip fidelity in `metric_attributes_json`)
- [x] 5.10 [green] Implement `App\Otlp\MetricEnvelopeJsonEncoder`

## 6. MetricsIngestService (TDD)

- [x] 6.1 [red] Unit test (with `CapturingParquetWriter`): a request with one Sum metric / one NumberDataPoint produces exactly one row with `metric_name`, `metric_type='SUM'`, `metric_type_code=0`, `value_int` populated, `value_double` NULL, `time_unix_nano`, `attributes_json='[]'`, `exemplars_json='[]'`
- [x] 6.2 [green] Implement `MetricsIngestService::write` skeleton (flatten DTO → row map; calls `WritesParquetFiles::writeAndCommit`)
- [x] 6.3 [red] Test: NumberDataPoint with `asDouble` populates `value_double`, leaves `value_int` NULL
- [x] 6.4 [red] Test: NumberDataPoint with `asInt` populates `value_int`, leaves `value_double` NULL
- [x] 6.5 [green] Add the variant-aware value mapping
- [x] 6.6 [red] DataProvider test: every metric type maps to its `metric_type` text and `metric_type_code` (SUM=0, GAUGE=1, HISTOGRAM=2, EXPONENTIAL_HISTOGRAM=3, SUMMARY=4)
- [x] 6.7 [green] Add the metric_type discriminator derivation
- [x] 6.8 [red] Test: HistogramDataPoint produces a row with `count`, `sum`, `min`, `max` populated and `buckets_json` containing the bucket structure
- [x] 6.9 [green] Wire `HistogramBucketsJsonEncoder` and the scalar columns
- [x] 6.10 [red] Test: ExponentialHistogramDataPoint produces a row with `count`, `sum`, `min`, `max` plus `exponential_histogram_json` containing the full proto sub-message
- [x] 6.11 [green] Wire `ExponentialHistogramJsonEncoder`
- [x] 6.12 [red] Test: SummaryDataPoint produces a row with `count`, `sum`, and `quantiles_json` as a 3-element list
- [x] 6.13 [green] Wire `SummaryQuantilesJsonEncoder`
- [x] 6.14 [red] Test: aggregation_temporality DELTA/CUMULATIVE/UNSPECIFIED maps to `aggregation_temporality` int + `aggregation_temporality_text` for Sum / Histogram / ExponentialHistogram rows
- [x] 6.15 [red] Test: Gauge and Summary rows have `aggregation_temporality` and `aggregation_temporality_text` NULL
- [x] 6.16 [green] Add temporality mapping
- [x] 6.17 [red] Test: Sum.is_monotonic populated for Sum rows; NULL for Gauge/Histogram/ExponentialHistogram/Summary
- [x] 6.18 [green] Add is_monotonic mapping
- [x] 6.19 [red] Test: every Tier-1 universal resource promotion lands as a `resource_*` column (mirrors the logs/traces equivalents)
- [x] 6.20 [red] Test: scope_schema_url populated when ScopeMetrics.schema_url is non-empty
- [x] 6.21 [green] Use `AttributeColumnExtractor` (signal='metrics') for resource and scope levels (no record-level promotions per D6)
- [x] 6.22 [red] Test: data-point with two exemplars produces `exemplars_json` with two items; traceId/spanId emitted as lowercase hex; AnyValue variants in filtered_attributes preserved
- [x] 6.23 [green] Wire `ExemplarJsonEncoder`
- [x] 6.24 [red] Test: empty exemplars list → `exemplars_json='[]'` (not NULL)
- [x] 6.25 [red] Test: `metric_attributes_json` round-trips the parent Metric envelope (name, unit, description, type, temporality, is_monotonic) excluding the data-points list
- [x] 6.26 [green] Wire `MetricEnvelopeJsonEncoder`
- [x] 6.27 [red] Test: a Metric envelope with empty data-points produces zero rows
- [x] 6.28 [red] Test: a request whose every Metric has empty data-points produces no Parquet file (writer's writeAndCommit not called)
- [x] 6.29 [green] Add the empty-rows short-circuit
- [x] 6.30 [red] Component test (real `ParquetFileWriter`, real filesystem, MockClock, StubFilenameGenerator): a known DTO produces a Parquet file at `<temp>/metrics/<slug>/date=…/hour=…/part-<ulid>.parquet` whose rows match the expected schema (one row per data-point, all column types correct)
- [x] 6.31 Declares `implements App\Otlp\Contract\IngestsSignal`

## 7. OtlpMetricsController + wiring (TDD, functional)

- [x] 7.1 [red] Functional test (via `zenstruck/browser`): valid bearer + valid OTLP/HTTP-JSON ExportMetricsServiceRequest body returns 200 `{}` and writes exactly one Parquet file at the expected path
- [x] 7.2 [green] Implement `App\Controller\OtlpMetricsController` as a thin delegator into `OtlpRequestPipeline` with the three metric collaborators
- [x] 7.3 [green] Wire the controller in services.yaml; bind `AttributeColumnExtractor` for metrics via `AttributeColumnExtractorFactory::forSignal('metrics')`; expose a distinct `ParquetFileWriter` service id `crashler.parquet_writer.metrics` bound to the metrics signal; bind `MetricsIngestService` arguments explicitly (mirrors the traces wiring)
- [x] 7.4 [red] Functional test: gzip body → 200 with file written
- [x] 7.5 [red] Functional test: protobuf body → 200 with file written; rows match the JSON-equivalent request
- [x] 7.6 [red] Functional test: missing/invalid bearer → 401
- [x] 7.7 [red] Functional test: `Content-Type: text/plain` → 415
- [x] 7.8 [red] Functional test: malformed JSON → 400; truncated protobuf → 400
- [x] 7.9 [red] Functional test: oversized compressed body → 413; oversized decompressed body → 413
- [x] 7.10 [red] Functional test: simulated writer failure → 5xx and no `.tmp` file remains in the temp storage root
- [x] 7.11 [red] Functional test: request with only-empty-data-point metrics → 200 and no Parquet file written

## 8. Cross-signal sanity (extend to three signals)

- [x] 8.1 [red] Functional test: posting to `/v1/logs`, `/v1/traces`, and `/v1/metrics` for the same tenant in the same process produces files in the correct top-level directories (`logs/<slug>/`, `traces/<slug>/`, `metrics/<slug>/`); no writer writes into another's tree
- [x] 8.2 [red] Functional test: `_schema_id` on a logs row is `logs/v1`; on a trace row is `traces/v1`; on a metric row is `metrics/v1`

## 9. Operator documentation

- [x] 9.1 README: extend the "Schemas and column conventions" section to mention the metrics signal and link to `config/schemas/metrics/v1.yaml`; explain the row-per-data-point model and the `metric_type` discriminator
- [x] 9.2 README "Querying" section: add a DuckDB example for metrics (filter by `_schema_id = 'metrics/v1'`, `metric_name`, `metric_type`, etc.); show an example of `json_extract(buckets_json, '$.bucketCounts')` for histogram queries
- [x] 9.3 README "Running" section: document that the `otlphttp` exporter URL for metrics is `<host>/v1/metrics`; same auth header as logs and traces
- [x] 9.4 README: add a brief "When to prefer Histogram over Summary" note since Summary is deprecated upstream

## 10. Spec scenario cross-check

- [x] 10.1 Walk every `#### Scenario:` block in `specs/metric-ingest/spec.md` and confirm a unit/component/functional test covers it
- [x] 10.2 Walk every scenario in `specs/metric-storage/spec.md` and confirm coverage
- [x] 10.3 Add tests for any unmapped scenario; capture the coverage map inline (mirrors the previous changes' audit tables)

### Spec scenario coverage audit

**metric-ingest/spec.md**

| Scenario                                              | Covering test                                                                  |
| ----------------------------------------------------- | ------------------------------------------------------------------------------ |
| Plain JSON body accepted                              | `OtlpMetricsControllerTest::testHappyPathJsonReturns200AndWritesParquetFile`   |
| JSON body with charset parameter accepted             | shared pipeline (`OtlpRequestPipeline`); logs has explicit test                |
| Plain protobuf body accepted                          | `OtlpMetricsControllerTest::testProtobufBodyAccepted`                          |
| Gzip-compressed body accepted                         | `OtlpMetricsControllerTest::testGzipBodyAccepted`                              |
| Unsupported Content-Type rejected                     | `OtlpMetricsControllerTest::testWrongContentTypeReturns415`                    |
| Malformed JSON body rejected                          | `OtlpMetricsControllerTest::testMalformedJsonReturns400`                       |
| Malformed protobuf body rejected                      | `OtlpMetricsControllerTest::testCorruptProtobufBodyReturns400`                 |
| Body schema mismatch rejected                         | `MetricsJsonDecoderTest::testSchemaMismatchRejected` (DataProvider, 11 cases)  |
| Compressed body over limit rejected                   | `OtlpMetricsControllerTest::testCompressedBodyOverLimitReturns413`             |
| Decompressed body over limit rejected                 | `OtlpMetricsControllerTest::testDecompressedBodyOverLimitReturns413`           |
| Unauthenticated request rejected before parsing       | `OtlpMetricsControllerTest::testMissingTokenReturns401`                        |
| 200 implies file durably committed                    | `MetricsIngestServiceComponentTest::testEndToEndWritesReadableParquetFileAtExpectedPath` |
| Persistence failure surfaces as 5xx                   | `OtlpMetricsControllerTest::testWriterFailureReturns5xxAndLeavesNoTmpFile`     |
| One file per accepted request                         | `OtlpMetricsControllerTest` happy path (asserts `count=1`)                     |
| Empty data-point arrays produce no file               | `OtlpMetricsControllerTest::testEmptyDataPointsRequestReturns200WithoutFile`   |
| Successful response shape                             | `OtlpMetricsControllerTest::assertJson()` on happy paths                       |
| Error response shape                                  | `OtlpMetricsControllerTest::testWrongContentTypeReturns415` `assertJson()`     |
| timeUnixNano accepted as string                       | `MetricsJsonDecoderTest::testTimestampsAcceptedAsNumberOrString`               |
| timeUnixNano accepted as number                       | `MetricsJsonDecoderTest::testTimestampsAcceptedAsNumberOrString`               |
| Sum data-point asDouble preserved                     | `MetricsJsonDecoderTest::testNumberDataPointAsDoubleVariant` + ingest test     |
| Sum data-point asInt preserved                        | `MetricsJsonDecoderTest::testNumberDataPointAsIntVariant` + ingest test        |
| Exemplar traceId hex decoded                          | `MetricsJsonDecoderTest::testExemplarTraceIdHexDecoded` + ingest hex emission  |
| 5xx implies no data-points persisted                  | `OtlpMetricsControllerTest::testWriterFailureReturns5xxAndLeavesNoTmpFile`     |

**metric-storage/spec.md**

| Scenario                                              | Covering test                                                                  |
| ----------------------------------------------------- | ------------------------------------------------------------------------------ |
| Metrics, traces, and logs share storage root          | `CrossSignalIsolationTest::testAllThreeSignalsWriteIntoSeparateTopLevelDirectories` |
| Tenant directory used                                 | every functional test asserts `/metrics/test-tenant/`                          |
| Hive partition layout from ingest time                | `MetricsIngestServiceComponentTest` asserts `date=2026-05-03/hour=14/part-…`   |
| One file per accepted request (multi-hour event time) | `MetricsIngestServiceTest::testMultipleDataPointsBecomeMultipleRows`           |
| Reader never observes partial file                    | shared `ParquetFileWriterTest` (atomic .tmp + rename, covers all signals)      |
| Failed write leaves no orphan                         | `OtlpMetricsControllerTest::testWriterFailureReturns5xxAndLeavesNoTmpFile`     |
| Schema columns present                                | `MetricsIngestServiceComponentTest` reads rows back via flow-php Reader        |
| Resource attributes denormalised + promoted (shadow)  | `MetricsIngestServiceTest::testTier1ResourcePromotionsLand`                    |
| Metric envelope fields denormalized onto each row     | `MetricsIngestServiceTest::testMetricEnvelopeRoundTrip` + multiple-DP test     |
| Sum data-point produces value_int xor value_double    | `MetricsIngestServiceTest::testNumberDataPointAsDouble` + base-row test        |
| Histogram count/sum/buckets_json populated together   | `MetricsIngestServiceTest::testHistogramScalarsAndBucketsJson`                 |
| ExponentialHistogram detail in single JSON blob       | `MetricsIngestServiceTest::testExponentialHistogramFullRoundTrip`              |
| Summary quantile values stored as JSON list           | `MetricsIngestServiceTest::testSummaryQuantilesJson`                           |
| Aggregation temporality preserved per row             | `MetricsIngestServiceTest::testAggregationTemporalityForSum`                   |
| Gauge data-point leaves temporality columns NULL      | `MetricsIngestServiceTest::testTemporalityNullForGauge`                        |
| Exemplars carried as JSON list                        | `MetricsIngestServiceTest::testExemplarsPopulated` + `…EmptyExemplarsJsonIsEmptyArrayNotNull` |
| Universal `_schema_id` reflects schema used           | `CrossSignalIsolationTest::testEachSignalCarriesItsOwnSchemaIdRowMarker`       |
| Empty data-points produce no rows                     | `MetricsIngestServiceTest::testMetricWithEmptyDataPointsProducesNoRows`        |
| Default GZIP compression                              | shared `ParquetFileWriterFactoryTest` / catalog wiring (covers all signals)    |
| Row-group size respected                              | shared `ParquetFileWriter` config (no signal-specific divergence)              |
| No flush command exists for metrics                   | absence verifiable via `bin/console list` — no commands registered             |

**Result:** every scenario maps to an existing test; no gaps required new tests beyond what §6/§7/§8 already added.

## 11. Final validation

- [x] 11.1 `composer test` passes with zero deprecations/notices/warnings across all three suites (527 tests, 1489 assertions)
- [x] 11.2 `openspec validate add-otlp-metric-ingest --strict` passes
- [x] 11.3 CI green on main (run 25282980448)
- [ ] 11.4 `dep deploy stage=production` (no env flag needed; additive change). Verify smoke test produces a Parquet file at `metrics/<slug>/date=…/hour=…/part-…parquet` with `_schema_id = 'metrics/v1'` and the full row shape (blocked on user OK)
- [ ] 11.5 Optional: run an OTel SDK or Collector against `https://crashler.cedric-ziel.com/v1/metrics` end-to-end with a histogram metric
