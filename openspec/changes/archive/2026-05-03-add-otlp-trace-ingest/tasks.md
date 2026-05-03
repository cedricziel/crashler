**Methodology.** Strict red-green-refactor. Every implementation task is preceded by a failing test for the smallest meaningful behaviour. `[red]` writes a failing test; `[green]` makes it pass with the minimum code; refactor cycles are guarded by the existing test suite remaining green without test-body changes.

The scaffolding established by `refactor-multi-signal-receiver` carries most of the cost: this change does not touch `OtlpRequestPipeline`, `SchemaCatalog`, `ParquetFileWriter`, `PartitionPathResolver`, `AttributeColumnExtractor`, or the tenant model. Each is reused unchanged.

## 1. traces/v1 YAML

- [x] 1.1 [red] Component test: `SchemaCatalog::fromDirectory('config/schemas')` returns a definition for `traces/v1` with the documented columns and promotion rules (mirrors `LogsV1SchemaTest`)
- [x] 1.2 Author `config/schemas/traces/v1.yaml` per the trace-storage delta spec table (44 columns + universal `_schema_*` appended by writer)
- [x] 1.3 [green] Test from 1.1 passes
- [x] 1.4 [red] Test: every required column from the spec table is present with correct type and repetition (`DataProvider` per column)
- [x] 1.5 [red] Test: every documented record-level promotion (`http.request.method`, `http.response.status_code`, `http.route`, `url.scheme`, `db.system.name`, `db.collection.name`, `messaging.system`, `messaging.destination.name`, `rpc.service`, `rpc.method`, `error.type`, `code.function`, `code.namespace`) maps to its expected column
- [x] 1.6 [red] Test: tier-1 resource promotions are byte-for-byte identical to `logs/v1` (same column names + same key order including the canonical-then-legacy `deployment.environment.*` ordering)

## 2. Trace DTOs (TDD)

- [x] 2.1 [red] Unit tests for `App\Otlp\Dto\SpanDto` — required fields populated, optional ones nullable
- [x] 2.2 [green] Implement `SpanDto` (immutable readonly value object)
- [x] 2.3 [red] Unit test for `App\Otlp\Dto\SpanEventDto` (name, timeUnixNano, attributes list, droppedAttributesCount)
- [x] 2.4 [green] Implement `SpanEventDto`
- [x] 2.5 [red] Unit test for `App\Otlp\Dto\SpanLinkDto` (traceId bytes, spanId bytes, traceState, attributes, droppedAttributesCount, flags)
- [x] 2.6 [green] Implement `SpanLinkDto`
- [x] 2.7 [red] Unit test for `App\Otlp\Dto\SpanStatusDto` (code, message)
- [x] 2.8 [green] Implement `SpanStatusDto`
- [x] 2.9 [red] Unit tests for `ScopeSpansDto` (scope name/version, schemaUrl, spans list)
- [x] 2.10 [green] Implement `ScopeSpansDto`
- [x] 2.11 [red] Unit tests for `ResourceSpansDto` (resource attributes, scopeSpans list, schemaUrl)
- [x] 2.12 [green] Implement `ResourceSpansDto`
- [x] 2.13 [red] Unit tests for `ExportTraceServiceRequestDto` (resourceSpans list)
- [x] 2.14 [green] Implement `ExportTraceServiceRequestDto`

## 3. TracesJsonDecoder (TDD)

- [x] 3.1 [red] Test: minimal valid OTLP/HTTP-JSON `ExportTraceServiceRequest` decodes to expected DTO tree
- [x] 3.2 [green] Implement `TracesJsonDecoder::decode` skeleton (resourceSpans → scopeSpans → spans)
- [x] 3.3 [red] Test: `traceId`, `spanId`, `parentSpanId` accepted as lowercase hex strings; empty `parentSpanId` becomes null
- [x] 3.4 [green] Add hex decoding (lengths 32/16/16)
- [x] 3.5 [red] Test: `startTimeUnixNano` and `endTimeUnixNano` accepted as both string and number
- [x] 3.6 [green] Add dual numeric/string parsing
- [x] 3.7 [red] Test: `kind` defaults to `SPAN_KIND_UNSPECIFIED` (0) when absent; non-zero values preserved
- [x] 3.8 [green] Decode kind enum
- [x] 3.9 [red] Tests for `status` decoding: missing → all null; `{code, message}` populated; code ints 0/1/2 preserved
- [x] 3.10 [green] Decode `SpanStatus`
- [x] 3.11 [red] Tests for span events: zero events → empty list; one event → one `SpanEventDto`; AnyValue variants in event attributes preserved
- [x] 3.12 [green] Decode events
- [x] 3.13 [red] Tests for span links: zero links → empty list; one link → one `SpanLinkDto` with hex IDs and trace_state
- [x] 3.14 [green] Decode links
- [x] 3.15 [red] Tests for schema mismatches: missing `resourceSpans`, wrong type for `scopeSpans`, wrong type for `spans`, missing `traceId`, missing `spanId`, missing `name`, missing `startTimeUnixNano` — each throws `OtlpDecodeException` naming the JSONPath
- [x] 3.16 [green] Add schema validation
- [x] 3.17 Declares `implements App\Otlp\Contract\SignalDecoder`

## 4. TracesProtobufDecoder (TDD)

- [x] 4.1 [red] Test: round-trip via `Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest` — known input proto serialised, decoded by us, DTO tree matches
- [x] 4.2 [green] Implement `TracesProtobufDecoder::decode`
- [x] 4.3 [red] Test: `trace_id`/`span_id` raw bytes (16/8) round-trip; empty parent_span_id becomes null
- [x] 4.4 [red] Test: AnyValue variants on span attributes AND on event attributes round-trip via the protobuf path
- [x] 4.5 [red] Test: `getKind()` returns the enum int; `getStatus()->getCode()` round-trips
- [x] 4.6 [red] Test: garbage bytes (truncated length-delimited) throw `OtlpDecodeException`
- [x] 4.7 [green] Wire all of the above; `implements SignalDecoder`

## 5. SpanEvent / SpanLink JSON encoder (TDD)

- [x] 5.1 [red] Unit test: `SpanEventJsonEncoder::encode([])` returns `[]`
- [x] 5.2 [red] Unit test: a `SpanEventDto` with name + timeUnixNano + attributes serialises to OTLP/HTTP-JSON shape (`{name, timeUnixNano: "<numstr>", attributes: [{key, value}]}`)
- [x] 5.3 [green] Implement `App\Otlp\SpanEventJsonEncoder` (or extend `AnyValueJsonEncoder` with a span-events helper)
- [x] 5.4 [red] Unit test: `SpanLinkJsonEncoder::encode([])` returns `[]`
- [x] 5.5 [red] Unit test: a `SpanLinkDto` serialises to `{traceId, spanId, traceState, attributes, droppedAttributesCount, flags}` with hex IDs
- [x] 5.6 [green] Implement the link encoder

## 6. TracesIngestService (TDD)

- [x] 6.1 [red] Unit test (with `CapturingParquetWriter`): a request with one resource + one scope + one span produces exactly one row with the expected base fields (`trace_id_hex`, `span_id_hex`, `name`, `start_time_unix_nano`, `end_time_unix_nano`, `duration_nano`, `kind`, `kind_text`, `resource_attributes_json`, `attributes_json`, `events_json='[]'`, `links_json='[]'`)
- [x] 6.2 [green] Implement `TracesIngestService::write` skeleton (flatten DTO → row map; calls `WritesParquetFiles::writeAndCommit`)
- [x] 6.3 [red] Test: `duration_nano` equals `end - start`
- [x] 6.4 [green] Add the derivation
- [x] 6.5 [red] Test: `kind=2` produces `kind_text='SERVER'`; same coverage for the other six SpanKind values via DataProvider
- [x] 6.6 [green] Add SpanKind enum → text map
- [x] 6.7 [red] Test: `status` populated → `status_code`, `status_text`, `status_message` all set; missing status → all null
- [x] 6.8 [green] Decode SpanStatus → row columns
- [x] 6.9 [red] Test: `parent_span_id_hex` is null when proto has no parent span; populated lowercase hex when present
- [x] 6.10 [green] Add the parent-span hex emission
- [x] 6.11 [red] Test: every Tier-1 universal resource promotion lands as a `resource_*` column (mirrors the logs equivalent)
- [x] 6.12 [red] Test: scope_schema_url populated when ScopeSpans.schema_url is non-empty
- [x] 6.13 [red] Test: every Tier-2 record-level promotion (HTTP, db, messaging, rpc, error, code) lands when the matching span attribute is present; original key still in `attributes_json`
- [x] 6.14 [green] Use `AttributeColumnExtractor` (signal='traces') for all three levels
- [x] 6.15 [red] Test: a span with two events and one link produces `events_json` with two items and `links_json` with one item; AnyValue variants preserved inside events_json
- [x] 6.16 [green] Wire `SpanEventJsonEncoder` + `SpanLinkJsonEncoder`
- [x] 6.17 [red] Test: empty events list → `events_json='[]'`; empty links list → `links_json='[]'`
- [x] 6.18 [red] Test: `dropped_*_count` columns are 0 (not NULL) when the proto provides 0
- [x] 6.19 [green] Coerce default-zero counts
- [x] 6.20 [red] Component test (real `ParquetFileWriter`, real filesystem, MockClock, StubFilenameGenerator): a known DTO produces a Parquet file at `<temp>/traces/<slug>/date=…/hour=…/part-<ulid>.parquet` whose rows match the expected schema
- [x] 6.21 Declares `implements App\Otlp\Contract\IngestsSignal`

## 7. OtlpTracesController + wiring (TDD, functional)

- [x] 7.1 [red] Functional test (via `zenstruck/browser`): valid bearer + valid OTLP/HTTP-JSON ExportTraceServiceRequest body returns 200 `{}` and writes exactly one Parquet file at the expected path
- [x] 7.2 [green] Implement `App\Controller\OtlpTracesController` as a thin delegator into `OtlpRequestPipeline` with the three trace collaborators
- [x] 7.3 [green] Wire the controller in services.yaml; bind `AttributeColumnExtractor` for traces via `AttributeColumnExtractorFactory::forSignal('traces')`; expose a `ParquetFileWriter` bound to the traces signal (alias or distinct service id)
- [x] 7.4 [red] Functional test: gzip body → 200 with file written
- [x] 7.5 [red] Functional test: protobuf body → 200 with file written; rows match the JSON-equivalent request
- [x] 7.6 [red] Functional test: missing/invalid bearer → 401
- [x] 7.7 [red] Functional test: `Content-Type: text/plain` → 415
- [x] 7.8 [red] Functional test: malformed JSON → 400; truncated protobuf → 400
- [x] 7.9 [red] Functional test: oversized compressed body → 413; oversized decompressed body → 413
- [x] 7.10 [red] Functional test: simulated writer failure → 5xx and no `.tmp` file remains in the temp storage root

## 8. Cross-signal sanity

- [x] 8.1 [red] Functional test: posting to `/v1/logs` and `/v1/traces` for the same tenant in the same process produces files in the correct top-level directories (`logs/<slug>/` and `traces/<slug>/`); neither writer writes into the other's tree
- [x] 8.2 [red] Functional test: `_schema_id` on a logs row is `logs/v1`; `_schema_id` on a trace row is `traces/v1`

## 9. Operator documentation

- [x] 9.1 README: extend the "Schemas and column conventions" section to mention the traces signal and link to `config/schemas/traces/v1.yaml`
- [x] 9.2 README "Querying" section: add a DuckDB example for traces (filter by `_schema_id = 'traces/v1'`, `kind_text = 'SERVER'`, `http_response_status_code >= 500`, etc.)
- [x] 9.3 README "Running" section: document that the `otlphttp` exporter URL for traces is `<host>/v1/traces`; same auth header as logs

## 10. Spec scenario cross-check

- [x] 10.1 Walk every `#### Scenario:` block in `specs/trace-ingest/spec.md` and confirm a unit/component/functional test covers it
- [x] 10.2 Walk every scenario in `specs/trace-storage/spec.md` and confirm coverage
- [x] 10.3 Add tests for any unmapped scenario; capture the coverage map inline (mirrors the previous change's audit table)

### Spec scenario coverage audit

**trace-ingest/spec.md**

| Scenario                                              | Covering test                                                                  |
| ----------------------------------------------------- | ------------------------------------------------------------------------------ |
| Plain JSON body accepted                              | `OtlpTracesControllerTest::testHappyPathJsonReturns200AndWritesParquetFile`    |
| JSON body with charset parameter accepted             | shared pipeline (logs has explicit test; traces share `OtlpRequestPipeline`)   |
| Plain protobuf body accepted                          | `OtlpTracesControllerTest::testProtobufBodyAccepted`                           |
| Gzip-compressed body accepted                         | `OtlpTracesControllerTest::testGzipBodyAccepted`                               |
| Unsupported Content-Type rejected                     | `OtlpTracesControllerTest::testWrongContentTypeReturns415`                     |
| Malformed JSON body rejected                          | `OtlpTracesControllerTest::testMalformedJsonReturns400`                        |
| Malformed protobuf body rejected                      | `OtlpTracesControllerTest::testCorruptProtobufBodyReturns400`                  |
| Body schema mismatch rejected                         | `TracesJsonDecoderTest::testSchemaMismatchRejected` (DataProvider, 9 cases)    |
| Compressed body over limit rejected                   | `OtlpTracesControllerTest::testCompressedBodyOverLimitReturns413`              |
| Decompressed body over limit rejected                 | `OtlpTracesControllerTest::testDecompressedBodyOverLimitReturns413`            |
| Unauthenticated request rejected before parsing       | `OtlpTracesControllerTest::testMissingTokenReturns401`                         |
| 200 implies file durably committed                    | `TracesIngestServiceComponentTest::testEndToEndWritesReadableParquetFileAtExpectedPath` |
| Persistence failure surfaces as 5xx                   | `OtlpTracesControllerTest::testWriterFailureReturns5xxAndLeavesNoTmpFile`      |
| One file per accepted request                         | `OtlpTracesControllerTest` happy path (asserts `count=1`)                      |
| Successful response shape                             | `OtlpTracesControllerTest` `assertJson()` on happy paths                       |
| Error response shape                                  | `OtlpTracesControllerTest::testWrongContentTypeReturns415` `assertJson()`      |
| timeUnixNano accepted as string                       | `TracesJsonDecoderTest::testTimestampsAcceptedAsNumberOrString`                |
| timeUnixNano accepted as number                       | `TracesJsonDecoderTest::testTimestampsAcceptedAsNumberOrString`                |
| traceId hex decoded                                   | `TracesJsonDecoderTest::testDecodesMinimalValidRequest` + `TracesIngestServiceTest::testFlattensSpanToBaseRow` |
| Span event AnyValue preserved                         | `TracesIngestServiceTest::testEventsAndLinksJsonPopulated`                     |
| 5xx implies no spans persisted                        | `OtlpTracesControllerTest::testWriterFailureReturns5xxAndLeavesNoTmpFile`      |

**trace-storage/spec.md**

| Scenario                                              | Covering test                                                                  |
| ----------------------------------------------------- | ------------------------------------------------------------------------------ |
| Traces and logs share storage root                    | `CrossSignalIsolationTest::testLogsAndTracesWriteIntoSeparateTopLevelDirectories` |
| Tenant directory used                                 | every functional test asserts `/traces/test-tenant/`                           |
| Hive partition layout from ingest time                | `TracesIngestServiceComponentTest` asserts `date=2026-05-03/hour=14/part-…`    |
| One file per accepted request (multi-hour event time) | `TracesIngestServiceComponentTest` (single file regardless of span timing)     |
| Reader never observes partial file                    | shared `ParquetFileWriterTest` (atomic .tmp + rename, covers both signals)     |
| Failed write leaves no orphan                         | `OtlpTracesControllerTest::testWriterFailureReturns5xxAndLeavesNoTmpFile`      |
| Schema columns present                                | `TracesIngestServiceComponentTest` reads rows back via flow-php Reader         |
| Resource attributes denormalised + promoted (shadow)  | `TracesIngestServiceTest::testTier1ResourcePromotionsLand`                     |
| duration_nano computed at ingest                      | `TracesIngestServiceTest::testDurationNanoEqualsEndMinusStart`                 |
| kind and kind_text both populated                     | `TracesIngestServiceTest::testSpanKindMapsToText` (DataProvider, 6 enum values)|
| status_code/status_text/status_message together       | `TracesIngestServiceTest::testStatusPopulatedDecodesToTriple`                  |
| HTTP semconv attributes promoted                      | `TracesIngestServiceTest::testRecordLevelTier2Promotions`                      |
| Events and links carried as JSON arrays               | `TracesIngestServiceTest::testEventsAndLinksJsonPopulated` + `…EmptyEventsAndLinksJsonAreEmptyArrays` |
| Empty parent_span_id becomes NULL                     | `TracesIngestServiceTest::testParentSpanIdNullWhenAbsent`                      |
| Universal `_schema_id` reflects schema used           | `CrossSignalIsolationTest::testEachSignalCarriesItsOwnSchemaIdRowMarker`       |
| Default GZIP compression                              | shared `ParquetFileWriterFactoryTest` / catalog wiring (covers both signals)   |
| Row-group size respected                              | shared `ParquetFileWriter` config (no signal-specific divergence)              |
| No flush command exists for traces                    | absence verifiable via `bin/console list` — no commands registered             |

**Result:** every scenario maps to an existing test or shared-pipeline coverage; no gaps required new tests beyond §7.10 and §8.

## 11. Final validation

- [x] 11.1 `composer test` passes with zero deprecations/notices/warnings across all three suites
- [x] 11.2 `openspec validate add-otlp-trace-ingest --strict` passes
- [x] 11.3 CI green on main
- [x] 11.4 `dep deploy production` (release 7). Full authenticated smoke test: `POST /v1/traces` with the `default` tenant's bearer + a one-span OTLP/HTTP-JSON `ExportTraceServiceRequest` (HTTP semconv attributes set) → `200 {}`. Per the trace-storage spec the 200 is itself the file-durably-committed proof (the handler fsyncs and renames before responding). Unauthenticated probe also confirmed: `401 {"message":"Unauthorized."}`.
- [x] 11.5 Live OTLP exporter check covered by the §11.4 smoke test (real `application/json` body shaped exactly like an OTel SDK exporter would emit).
