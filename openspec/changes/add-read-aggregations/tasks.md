## 1. Configuration

- [ ] 1.1 Add parameters `crashler.read.aggregate.max_groups` (default 200) and `crashler.read.aggregate.max_intervals` (default 720) wired to env vars `CRASHLER_READ_AGGREGATE_MAX_GROUPS` and `CRASHLER_READ_AGGREGATE_MAX_INTERVALS`
- [ ] 1.2 Reuse existing time-window, page-size, and timeout configuration

## 2. Aggregation primitives

- [ ] 2.1 Add `App\Read\Compute\Aggregations\Accumulator` interface (`feed(int|float $value): void`, `value(): int|float`, `sampleCount(): int`)
- [ ] 2.2 Implement `CountAccumulator`, `SumAccumulator`, `MinAccumulator`, `MaxAccumulator`, `AvgAccumulator`
- [ ] 2.3 Implement `App\Read\Compute\Aggregations\TDigest` (Dunning's t-digest, pure PHP, default compression 100)
- [ ] 2.4 Implement `PercentileAccumulator(float $q)` that wraps `TDigest` and returns the requested quantile from `value()`
- [ ] 2.5 Add `App\Read\Compute\Aggregations\AccumulatorFactory::for(string $function, ?string $column): Accumulator` mapping function names to constructors

## 3. Aggregating scanner

- [ ] 3.1 Extract a shared `App\Read\Compute\RowIterator` (or refactor `ParquetScanner` so its inner row-emission loop is reusable) — the iteration logic with predicate evaluation, file ordering, partition pruning, row-group push-down, and timeout enforcement
- [ ] 3.2 Add `App\Read\Compute\AggregatingScanner` that consumes the shared row iterator. Per row: compute the group key (from the `groupBy` columns) + bucket key (from `interval`), look up or create the accumulator, feed the column value
- [ ] 3.3 Result materialisation: walk the per-(group, bucket) accumulators, emit a sorted result list
- [ ] 3.4 Cardinality enforcement: when a new group key would push `count(distinct groups) > max_groups`, abort with a typed exception that the processor surfaces as 400
- [ ] 3.5 Interval enforcement: pre-compute `count(buckets) = ceil((until - since) / interval)`; if > `max_intervals`, abort before scanning with 400

## 4. Resources and processors

- [ ] 4.1 Add `App\Read\Resource\AggregateResult` (or per-signal subclasses). Properties: `group`, `bucketStartUnixNano`, `function`, `column`, `value`, `sampleCount`. Use the same string-int64 convention as `timeUnixNano`
- [ ] 4.2 Add a single `#[ApiResource]` for `AggregateResult` declaring three `#[GetCollection]` operations:
  - `uriTemplate: '/v1/logs/aggregate'`, processor `AggregateLogsProcessor`
  - `uriTemplate: '/v1/traces/aggregate'`, processor `AggregateTracesProcessor`
  - `uriTemplate: '/v1/metrics/aggregate'`, processor `AggregateMetricsProcessor`
- [ ] 4.3 Each processor: parse + validate parameters, compile filter parameters into predicates (reusing the GET search's compiler), enforce per-signal `function` / `column` / `groupBy` allow-lists, dispatch to `AggregatingScanner`, shape results

## 5. Drill-down affordance

- [ ] 5.1 Each aggregate response stashes a top-level `_links.search` URL on a request attribute the existing `NextCursorInjector` (or a new sibling listener) can inject per format
- [ ] 5.2 The injected URL points at the GET search for the same signal with the same filter parameters and the same `since`/`until` (omits `function`, `column`, `groupBy`, `interval`)

## 6. Tests

- [ ] 6.1 Accumulator unit tests: known datasets, known answers, including edge cases (empty stream, single value, alternating min/max)
- [ ] 6.2 t-digest tests: uniform, exponential, bimodal datasets; quantile error bounded under 2% at p99 for >10k samples; cross-check against fixture outputs from a reference implementation
- [ ] 6.3 Functional test (logs): `function=count&groupBy=service` returns the right per-service counts
- [ ] 6.4 Functional test (traces): `function=p99&column=httpResponseStatusCode&groupBy=service` over a fixture with known status-code distribution
- [ ] 6.5 Functional test (metrics): `function=sum&column=valueDouble&groupBy=metricName` over a SUM-typed fixture
- [ ] 6.6 Cardinality cap test: synthesize a fixture whose group-by keys exceed 200 distinct values; expect 400
- [ ] 6.7 Interval cap test: 30-day window with `interval=1m` (43,200 buckets) → 400 before scanning starts
- [ ] 6.8 Drill-down test: the response's `_links.search` URL, when followed, returns the rows the aggregate summarised
- [ ] 6.9 Format negotiation test: same query under all four formats; assert the row shape renders correctly in each
- [ ] 6.10 Auth test: bearer required; cross-tenant data isolation enforced via the same path-glob mechanism
- [ ] 6.11 Verify all existing read-API tests still pass

## 7. Documentation

- [ ] 7.1 Add an "Aggregations" subsection to the project README's "Reading data" section. Include worked examples for `count`, `p99`, `groupBy`, `interval`
- [ ] 7.2 Document the cardinality cap and the interval cap with operator-actionable advice
- [ ] 7.3 Per the `add-read-api-spec-examples` requirement: declare simple and medium-complex examples on every aggregate parameter and on the response body
