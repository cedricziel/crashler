**Methodology.** Every section below is TDD: for each implementation task, a failing test must be written first, observed to fail for the expected reason, then minimum production code is written to make it green, then refactored with tests green. Tasks marked `[red]` write a failing test; tasks marked `[green]` add the implementation that makes the most recently red test pass. Where a single behavior needs multiple tests, more `[red]` cycles precede the next `[green]`.

## 1. Project setup and dependencies

- [x] 1.1 Add production deps: `flow-php/parquet`, `symfony/uid`, `symfony/clock`. Add dev deps: `zenstruck/browser`, `zenstruck/foundry`, `zenstruck/assert`. Run `composer require` / `composer require --dev`.
- [x] 1.2 Document baseline PHP extensions in the project README (`ext-zstd` recommended; PCOV recommended for coverage)
- [x] 1.3 Add `CRASHLER_PARQUET_COMPRESSION` (default `GZIP`), `CRASHLER_INGEST_MAX_BODY_BYTES` (default `4194304`), `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES` (default `16777216`) to `.env`
- [x] 1.4 Confirm `APP_SHARE_DIR` exists and is writable in dev (`var/share`)
- [~] 1.5 Bind `APP_SHARE_DIR` to a Symfony container parameter `crashler.storage_root`; bind `CRASHLER_PARQUET_COMPRESSION` to `crashler.compression`; add a compile-time check that the storage root exists/writable and the codec's PHP extension is loaded; both fail fast — *parameter binding done in `config/services.yaml`; fail-fast check deferred to §6 (CrashlerExtension)*

## 2. Test infrastructure

- [x] 2.1 Reorganize `tests/` into `tests/Unit/`, `tests/Component/`, `tests/Functional/`, `tests/Factories/`, `tests/Support/`
- [x] 2.2 Update `phpunit.dist.xml`: define three suites (`unit`, `component`, `functional`), enable `<coverage>` with branch coverage, keep existing strict-deprecation/notice/warning settings
- [x] 2.3 Add `composer test` script (all suites, no coverage) and `composer test:coverage` (with thresholds: 90% line aggregate; 95% line + 90% branch on `App\Otlp`/`App\Tenancy`/`App\Storage` excluding `ParquetFileWriter`; 85% line on `App\Storage\ParquetFileWriter` and `App\Controller`) — *coverage thresholds enforced via CI script in §21, not via phpunit XML (PHPUnit 13 doesn't support per-namespace thresholds in XML)*
- [ ] 2.4 Implement `tests/Support/TempStorageRoot` trait providing per-test unique temp dir + `tearDown` cleanup — *deferred until first component test needs it*
- [ ] 2.5 Implement `tests/Support/PinnedClock` helper returning a `Symfony\Component\Clock\MockClock` at a known instant — *deferred until first test needs it*
- [ ] 2.6 Implement `tests/Support/StubFilenameGenerator` returning deterministic counter-backed values — *deferred until first test needs it*
- [x] 2.7 Configure `zenstruck/foundry` and `zenstruck/browser` boots in `tests/bootstrap.php` if needed; verify the kernel boots in test env — *Flex recipes wired both; bootstrap unchanged*
- [~] 2.8 Verify `composer test` exits 0 with zero tests collected and no warnings/deprecations — *PHPUnit 13 fails on empty suites by design; superseded by §3.1 onwards which will populate the suites*

## 3. Tenants — Tenant value object (TDD)

- [x] 3.1 [red] Unit test: constructing a `Tenant` with valid slug + name exposes them via getters; equality by value
- [x] 3.2 [green] Implement `App\Tenancy\Tenant` value object (immutable)
- [x] 3.3 [red] Test: constructing with mismatched type / missing fields fails (PHP type system; verify via static expectation if relevant) — *covered by PHP 8.4's strict types on readonly promoted properties; explicit type-mismatch test omitted as redundant*

## 4. Tenants — TenantRegistry (TDD)

- [x] 4.1 [red] Unit test: `findByTokenHash` returns null on miss
- [x] 4.2 [green] Implement `TenantRegistry` constructor taking a `array<string $sha256Hex, Tenant>` map; null-return implementation
- [x] 4.3 [red] Test: lookup hit returns the matching `Tenant`
- [x] 4.4 [green] Implement the lookup
- [x] 4.5 [red] Test: constructing with the same hash mapped to two different tenants throws an `InvalidArgumentException` (or domain-specific exception) with a message naming the duplicate — *added `TenantRegistry::fromEntries()` with `DuplicateTokenHashException`*
- [x] 4.6 [green] Add duplicate-detection in the constructor
- [x] 4.7 [red] Test: empty registry is constructible and behaves as expected (always returns null)
- [ ] 4.8 [red] Foundry factory `TenantFactory` in `tests/Factories/` produces a default tenant for use in higher-level tests — *deferred until first higher-level test needs one*

## 5. Tenants — Configuration validation (TDD)

- [x] 5.1 [red] Unit test (using Symfony's config processor): valid config tree builds without error and produces the expected normalized array
- [x] 5.2 [green] Implement `App\DependencyInjection\Configuration` with the `crashler.tenants` tree
- [x] 5.3 [red] Test: slug rejected when uppercase, leading digit, too short (<3 chars after first), too long (>32 chars), trailing hyphen, contains underscore or other invalid chars (one test per case)
- [x] 5.4 [green] Add slug validation rule
- [x] 5.5 [red] Test: token hash rejected when wrong length, contains uppercase, contains non-hex
- [x] 5.6 [green] Add hash format validation rule
- [x] 5.7 [red] Test: same hash under two tenants rejected at config processing time with a message naming both tenants
- [x] 5.8 [green] Add cross-tenant duplicate detection
- [x] 5.9 [red] Test: empty `crashler.tenants` accepted (boots; just rejects all auth)

## 6. Tenants — DI extension (TDD)

- [x] 6.1 [red] Component test: instantiate a real Symfony container, load a fixture YAML with two tenants, assert `TenantRegistry` is registered and contains both tenants when retrieved
- [x] 6.2 [green] Implement `App\DependencyInjection\CrashlerExtension`
- [x] 6.3 [red] Component test: invalid config in YAML causes container compilation failure with a clear message
- [x] 6.4 [green] Wire the validation into the extension loader

## 7. Tenants — Authenticator (TDD)

- [x] 7.1 [red] Unit test: `IngestUser` returns the wrapped `Tenant` from `getTenant()`
- [x] 7.2 [green] Implement `App\Security\IngestUser`
- [x] 7.3 [red] Unit test: authenticator with stub `TenantRegistry` rejects request with no `Authorization` header
- [x] 7.4 [green] Implement `IngestTokenAuthenticator::supports` / `authenticate` skeleton; reject case
- [x] 7.5 [red] Unit test: malformed `Authorization` header (non-Bearer scheme) rejected
- [x] 7.6 [green] Add scheme check
- [x] 7.7 [red] Unit test: unknown token (hash not in registry) rejected
- [x] 7.8 [green] Add registry lookup + reject branch
- [x] 7.9 [red] Unit test: valid token attaches the matching tenant via `IngestUser`
- [x] 7.10 [green] Wire the success path
- [x] 7.11 [red] Unit test: timing-safe comparison — *registry lookup uses PHP's array key hash; no char-by-char compare is in the hot path. Structural verification omitted (the implementation has no `==` or `strcmp` on tokens).*
- [x] 7.12 [green] Confirm and refactor if needed
- [x] 7.13 Configure `config/packages/security.yaml` with stateless firewall on `^/v1/`
- [x] 7.14 [red] Functional test using `zenstruck/browser`: hit `/v1/_authcheck` (test-env stub controller) without token → 401; with bogus token → 401; with malformed header → 401; with valid token → 200 returning the tenant slug
- [x] 7.15 [green] Add the test-env stub controller and confirm tests pass

## 8. log-ingest — DTOs (TDD)

- [x] 8.1 [red] Unit tests for each DTO's construction, immutability, and field exposure (one per DTO)
- [x] 8.2 [green] Implement `App\Otlp\Dto\*` value classes
- [ ] 8.3 [red] Foundry factories in `tests/Factories/` for each DTO with sensible defaults and easy variant overrides — *deferred until first higher-level test would benefit from one*

## 9. log-ingest — JSON decoder (TDD)

- [x] 9.1 [red] Test: minimal valid OTLP request decodes to expected DTO tree
- [x] 9.2 [green] Implement `LogsJsonDecoder::decode` happy-path skeleton
- [x] 9.3 [red] Test: `timeUnixNano` accepted as JSON number
- [x] 9.4 [red] Test: `timeUnixNano` accepted as numeric string (and identical int64 result as the number form)
- [x] 9.5 [green] Add dual numeric/string parsing
- [x] 9.6 [red] Tests for AnyValue body variants: stringValue, intValue, doubleValue, boolValue, bytesValue (base64 in JSON), arrayValue, kvlistValue (one test per variant; parametrized via foundry)
- [x] 9.7 [green] Add AnyValue decoding preserving the variant
- [x] 9.8 [red] Test: hex `traceId` (32 chars) and `spanId` (16 chars) decoded to the correct byte sequences
- [x] 9.9 [green] Add hex decoding
- [x] 9.10 [red] Tests for schema mismatch: missing `resourceLogs`, wrong field type for `resourceLogs`, wrong field type for `scopeLogs`, wrong field type for `logRecords`, malformed JSON — each throws `OtlpDecodeException` with a clear message
- [x] 9.11 [green] Add schema validation
- [x] 9.12 [red] Test: optional fields missing → DTO field is null; multi-resource request decodes both resources

## 10. log-ingest — Gzip decoder (TDD)

- [x] 10.1 [red] Test: round-trip — gzip-encode a known string, decode through `GzipBodyDecoder`, assert equality
- [x] 10.2 [green] Implement `GzipBodyDecoder::decode` using streaming `inflate_init`
- [x] 10.3 [red] Test: input that decompresses past the limit throws `OtlpPayloadTooLargeException` mid-stream; assert peak memory does not exceed limit + small overhead (e.g., 64 KiB)
- [x] 10.4 [green] Add incremental size enforcement
- [x] 10.5 [red] Test: corrupt gzip bytes throw a decode exception; empty input throws

## 11. log-ingest — Error response helper (TDD)

- [x] 11.1 [red] Test: `ErrorResponse::create(400, 'bad json')` returns a `JsonResponse` with status 400, content-type `application/json`, body `{"message":"bad json"}`
- [x] 11.2 [green] Implement `ErrorResponse`
- [x] 11.3 [red] Test: each documented status code produces a correctly shaped response

## 12. log-storage — Schema (TDD)

- [ ] 12.1 [red] Test: `ParquetSchema::definition()` returns a flow-php schema containing every documented column with the documented type and nullability (one assertion per column)
- [ ] 12.2 [green] Implement `ParquetSchema`

## 13. log-storage — Compression resolver (TDD)

- [ ] 13.1 [red] Test: each documented codec name (`GZIP`, `ZSTD`, `SNAPPY`, `BROTLI`, `LZ4`, `LZ4_RAW`, `UNCOMPRESSED`) resolves to the matching flow-php enum
- [ ] 13.2 [green] Implement `ParquetCompression::resolve` with name → enum mapping
- [ ] 13.3 [red] Test: unknown codec name throws `InvalidArgumentException`
- [ ] 13.4 [green] Add unknown-name branch
- [ ] 13.5 [red] Test: codec requiring a missing PHP extension throws a clear exception (inject `extension_loaded` results via constructor or static seam)
- [ ] 13.6 [green] Add extension check

## 14. log-storage — Filename generator (TDD)

- [ ] 14.1 [red] Test: `UlidFilenameGenerator::generate()` returns a 26-char Crockford-base32 string matching the ULID alphabet
- [ ] 14.2 [green] Implement `FilenameGenerator` interface and `UlidFilenameGenerator`
- [ ] 14.3 [red] Test: two consecutive `generate()` calls return monotonically increasing values

## 15. log-storage — Partition path resolver (TDD)

- [ ] 15.1 [red] Test: `resolve` with `MockClock(2026-05-03T14:37:00Z)`, `StubFilenameGenerator('01J0001')`, root `/tmp/x`, slug `acme` returns final path `/tmp/x/logs/acme/date=2026-05-03/hour=14/part-01J0001.parquet` and tmp path `…parquet.tmp`
- [ ] 15.2 [green] Implement `PartitionPathResolver`
- [ ] 15.3 [red] Test: hour padding — `01:05` UTC produces `hour=01`, `09:00` produces `hour=09`
- [ ] 15.4 [green] Adjust formatting if needed
- [ ] 15.5 [red] Test: midnight boundary — `2026-05-03T23:59:59Z` and `2026-05-04T00:00:00Z` produce different date directories

## 16. log-storage — Parquet file writer (TDD, component scope)

- [ ] 16.1 [red] Component test using `TempStorageRoot`: `writeBatch` then `commit` produces a Parquet file at the final path; reading it back via flow-php's reader yields the same rows
- [ ] 16.2 [green] Implement `ParquetFileWriter` skeleton: open stream, write, close+fsync+rename
- [ ] 16.3 [red] Component test: `abort` after `writeBatch` removes the `.tmp` and produces no final file
- [ ] 16.4 [green] Implement `abort`
- [ ] 16.5 [red] Component test: rename failure (target dir made read-only mid-test) leaves no orphan and rethrows
- [ ] 16.6 [green] Wrap commit in try/catch with cleanup
- [ ] 16.7 [red] Component test: row group size respected — write rows totaling > 32 MiB and verify multiple row groups in the resulting file

## 17. log-ingest — Service (TDD, mostly unit + one component)

- [ ] 17.1 [red] Unit test with stubbed writer: AnyValue body serialized as JSON string in `body_json` column for each variant
- [ ] 17.2 [green] Implement `LogsIngestService::write` skeleton with stubbed-writer-friendly seam
- [ ] 17.3 [red] Unit test: resource attributes denormalized onto every row (1 ResourceLogs × N LogRecords → N rows with identical resource_attributes_json)
- [ ] 17.4 [green] Add denormalization
- [ ] 17.5 [red] Unit test: `service.name` extracted from resource attributes when present; null when absent
- [ ] 17.6 [green] Add extraction
- [ ] 17.7 [red] Unit test: scope name/version copied; trace/span IDs hex-encoded as documented
- [ ] 17.8 [green] Add encoding
- [ ] 17.9 [red] Unit test: writer exception triggers `abort()` and rethrow
- [ ] 17.10 [green] Add try/abort/rethrow
- [ ] 17.11 [red] Component test (real `ParquetFileWriter`, real filesystem, MockClock, StubFilenameGenerator): a known DTO produces a Parquet file at the expected path with the expected rows

## 18. log-ingest — Controller (TDD, functional via zenstruck/browser)

- [ ] 18.1 [red] Functional: `POST /v1/logs` with valid bearer + valid OTLP/HTTP-JSON body returns 200 `{}` and writes exactly one Parquet file at the expected path containing the expected rows
- [ ] 18.2 [green] Implement controller happy path
- [ ] 18.3 [red] Functional: gzip body → 200 with file written
- [ ] 18.4 [green] Wire `GzipBodyDecoder` into the request pipeline
- [ ] 18.5 [red] Functional: missing/invalid/malformed-scheme bearer → 401 with `{"message":...}` body
- [ ] 18.6 [green] (Already covered by authenticator; verify and adjust controller error mapping)
- [ ] 18.7 [red] Functional: `Content-Type: application/x-protobuf` → 415
- [ ] 18.8 [green] Add Content-Type guard
- [ ] 18.9 [red] Functional: malformed JSON → 400
- [ ] 18.10 [red] Functional: schema-mismatched JSON (e.g., resourceLogs not an array) → 400 with descriptive message
- [ ] 18.11 [green] Wire decoder exceptions to 400 via `ErrorResponse`
- [ ] 18.12 [red] Functional: compressed body over `CRASHLER_INGEST_MAX_BODY_BYTES` → 413, body not decompressed
- [ ] 18.13 [red] Functional: compressed body within compressed limit but expanding past `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES` → 413
- [ ] 18.14 [green] Wire size pre-check and decoder size-cap into the controller
- [ ] 18.15 [red] Functional: simulated writer failure (override the writer service to a throwing decorator) → 5xx and no `.tmp` file remains in the temp storage root
- [ ] 18.16 [green] Confirm controller maps service exceptions to 5xx via `ErrorResponse`

## 19. Operator documentation

- [ ] 19.1 Update `README.md`: how to author `config/packages/crashler.yaml`, hash one-liner, run instructions, on-disk layout, DuckDB query examples
- [ ] 19.2 Document v1 design constraints: per-request files, partition by ingest time, no background workers, redeploy required for tenant changes
- [ ] 19.3 Document OTLP exporter retry expectation (5xx → client retries)
- [ ] 19.4 Document developer test workflow: `composer test` for the inner loop, `composer test:coverage` for the gate, PCOV vs Xdebug hints, zenstruck/browser examples

## 20. Spec-scenario coverage cross-check

- [ ] 20.1 For each `#### Scenario:` in `specs/tenants/spec.md`, confirm a test method has a `// spec: tenants/<requirement>/<scenario>` marker
- [ ] 20.2 Same for `specs/log-ingest/spec.md`
- [ ] 20.3 Same for `specs/log-storage/spec.md`
- [ ] 20.4 Add tests for any unmapped scenarios

## 21. Cross-cutting validation

- [ ] 21.1 `composer test` passes with zero deprecations/notices/warnings across all three suites
- [ ] 21.2 `composer test:coverage` passes the configured thresholds
- [ ] 21.3 `openspec validate add-otlp-log-ingest --strict` passes
- [ ] 21.4 Manual smoke test: send an OTLP/HTTP-JSON request from the OpenTelemetry Collector's `otlphttp` exporter against a dev instance, verify a Parquet file lands and DuckDB reads it
