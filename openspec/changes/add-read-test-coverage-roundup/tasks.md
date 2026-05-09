## 1. Time-window row-group push-down test

- [x] 1.1 Added `testRowGroupPushDownSkipsByTimeWindowRange` to `tests/Component/Read/ParquetScannerTest.php`
- [x] 1.2 Helper writes three logs files in the same partition with `time_unix_nano` per record placed before / inside / after the requested `[since, until]`
- [x] 1.3 Scanner with `ColumnInRange('time_unix_nano', ...)` returns only the inside-window file's rows; `groupsScanned == 1`, `groupsSkipped == 2`

## 2. Schema-absent column unit test

- [x] 2.1 Added `tests/Unit/Read/Compute/RowGroupSkipperTest.php`
- [x] 2.2 Test opens a real logs Parquet file and reads its row group + schema (no mocking of flow-php classes)
- [x] 2.3 Asserts `RowGroupSkipper::canSkip()` returns false for a `ColumnGreaterEqual('http_response_status_code', 500)` predicate (column absent from logs schema)
- [x] 2.4 Bonus assertion: `ColumnEquals('resource_service_name', 'no-such-service')` (string predicate) is also indeterminate; numeric `ColumnInRange` IS refuted when bounds are disjoint

## 3. Multi-attribute traces functional test

- [x] 3.1 Added `tests/Functional/Read/MultiAttributeTracesFiltersTest.php`
- [x] 3.2 Helper writes 3 spans labelled a/b/c carrying `{POST,/checkout}` / `{POST,/products}` / `{GET,/checkout}`
- [x] 3.3 GET `/v1/traces?attribute.http.method=POST&attribute.http.route=/checkout` returns only span `a`
- [x] 3.4 Assertion: exactly one row, name == "a"

## 4. Multi-attribute metrics functional test

- [x] 4.1 Added `tests/Functional/Read/MultiAttributeMetricsFiltersTest.php`
- [x] 4.2 Helper writes 3 metric data points with attribute pairs `{prod, eu-west-1}` / `{prod, us-east-1}` / `{staging, eu-west-1}`
- [x] 4.3 GET `/v1/metrics?attribute.k8s.cluster=prod&attribute.region=eu-west-1` returns only the doubly-matching row
- [x] 4.4 Assertion: exactly one row, metricName == "http.server.duration.a"

## 5. POST search body-size 413 unit test

- [x] 5.1 Added `tests/Unit/Read/Http/PostSearchRequestParserTest.php`
- [x] 5.2 Synthetic Request with body just over the 64 KiB cap → `InvalidPostSearchBodyException` with `statusCode == 413` and message containing `65536`
- [x] 5.3 Direct parser invocation (bypasses zenstruck/browser, which obscures body-size enforcement)
- [x] 5.4 Bonus tests for `415` (wrong Content-Type) and `400` (malformed JSON) on the same parser; covers the parser's full error envelope

## 6. Aggregations cardinality cap functional test

- [x] 6.1 Added `tests/Functional/Read/AggregateCardinalityCapTest.php`
- [x] 6.2 Helper writes 201 distinct services (one log per service) into one partition under one tenant
- [x] 6.3 GET `/v1/logs/aggregate?function=count&groupBy=service` over the fixture returns 400
- [x] 6.4 Response message contains "200" (the configured cap)

## 7. Verification

- [x] 7.1 Full test suite: 710/710 green (+11 new tests across the 6 sections, no existing test regressed)
- [x] 7.2 No production code changed; no test failed on first run; no bugs discovered
