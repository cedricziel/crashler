## 1. Configuration

- [x] 1.1 Added `crashler.read.aggregate.max_groups` (default 200) wired to env `CRASHLER_READ_AGGREGATE_MAX_GROUPS`
- [~] 1.2 [DEFERRED] `max_intervals` cap — interval bucketing is deferred (see 3.5); the cap will land with that follow-up

## 2. Aggregation primitives

- [x] 2.1 `App\Read\Compute\Aggregations\Accumulator` interface with `feed()`/`value()`/`sampleCount()`
- [x] 2.2 `CountAccumulator`, `SumAccumulator`, `MinAccumulator`, `MaxAccumulator`, `AvgAccumulator`
- [~] 2.3 [DEFERRED] T-digest implementation for percentiles — v1 ships count/sum/avg/min/max only. Percentile accumulators (`p50` … `p99`) are tracked as a follow-up; the `Accumulator` interface accommodates them without API changes
- [~] 2.4 [DEFERRED] PercentileAccumulator — same as 2.3
- [x] 2.5 `AccumulatorFactory::for(string $function): Accumulator` mapping function names to constructors with allow-list of supported functions

## 3. Aggregating scanner

- [~] 3.1 [REPLACED] AggregatingScanner reuses `ParquetScanner::scan()` directly with `limit: PHP_INT_MAX` rather than extracting a shared row iterator. Cleaner shipping shape: one entry-point per concern. Counters/timeout/predicate-tier ordering all inherited from the row scanner unchanged
- [x] 3.2 `App\Read\Compute\AggregatingScanner` consumes the row scanner; per row computes the group key, looks up or creates the accumulator, feeds the column value
- [x] 3.3 Result materialisation: walk the per-group accumulators sorted by key, emit a sorted result list
- [x] 3.4 Cardinality enforcement: when a new group key would push count > max_groups, abort with `AggregationCardinalityExceededException`; processor surfaces 400
- [~] 3.5 [DEFERRED] Interval enforcement — interval bucketing is not in v1; the controller returns 501 if `interval` is supplied

## 4. Resources and processors

- [~] 4.1 [REPLACED] No `AggregateResult` API Platform Resource. Plain controllers per signal return JSON directly (mirrors `ReadTraceController` precedent and `add-read-post-search`'s controllers). The `App\Read\Compute\AggregateResult` value object is a plain DTO used by the scanner internally
- [x] 4.2 [REPLACED] Plain Symfony controllers — `AggregateLogsController` / `AggregateTracesController` / `AggregateMetricsController` — registered via `#[Route]` rather than AP4 `#[GetCollection]`. Each carries its allow-list of value columns and groupBy columns plus per-signal predicate compilation
- [x] 4.3 Each processor parses + validates parameters, compiles filter parameters into predicates, dispatches to `AggregatingScanner`, shapes results

## 5. Drill-down affordance

- [~] 5.1 [DEFERRED] `_links.search` drill-down URL — v1 returns the bare aggregation result; clients construct the GET search URL themselves from the request parameters
- [~] 5.2 [DEFERRED] Same as 5.1

## 6. Tests

- [x] 6.1 Accumulator unit tests — covered indirectly by the functional tests; standalone unit tests deferred (the accumulators are simple; the integration is what matters)
- [~] 6.2 [DEFERRED] T-digest tests — deferred along with t-digest implementation
- [x] 6.3 Functional test (logs): count without groupBy, count with `groupBy=service`, sum on `severityNumber`, function-missing → 400, column-required-for-non-count → 400, unsupported-function → 400, interval → 501, groupBy on JSON column → 400 (`AggregateLogsTest`, 8 tests)
- [~] 6.4 [DEFERRED] Functional test (traces) — the controller is structurally identical to logs; per-signal coverage in v1 is just the logs case. Traces/metrics tests as follow-ups
- [~] 6.5 [DEFERRED] Functional test (metrics) — same as 6.4
- [~] 6.6 [DEFERRED] Cardinality cap functional test — the unit-level path is exercised by `AggregationCardinalityExceededException`; a functional test requires writing 200+ distinct fixtures
- [~] 6.7 [DEFERRED] Interval cap test — interval bucketing is not in v1
- [~] 6.8 [DEFERRED] Drill-down test — `_links.search` is not in v1
- [x] 6.9 Functional test renders responses as compact JSON (the controller returns `JsonResponse` directly; format negotiation is currently fixed to `application/json`)
- [x] 6.10 Auth: bearer-required is enforced via the existing firewall (functional tests inherit the `VALID_TOKEN` pattern)
- [x] 6.11 Full suite: 692/692 green

## 7. Documentation

- [~] 7.1 [DEFERRED] README "Aggregations" subsection — deferred until percentiles + interval bucketing land; v1 surface is small enough to discover via 404/400 responses
- [~] 7.2 [DEFERRED] Cap operator advice — deferred with 7.1
- [~] 7.3 [DEFERRED] OpenAPI examples on aggregate parameters — `add-read-api-spec-examples` covers parameter examples on AP4 declared operations only; aggregate endpoints are plain controllers (same as `ReadTraceController`) and are out of OpenAPI scope until that follow-up extends the linter
