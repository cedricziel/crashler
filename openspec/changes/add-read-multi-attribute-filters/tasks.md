## 1. Configuration

- [ ] 1.1 Add `crashler.read.max_attribute_filters` parameter (default 5) to `config/services.yaml` (or the appropriate config file alongside the other `crashler.read.*` parameters)
- [ ] 1.2 Bind it to env var `CRASHLER_READ_MAX_ATTRIBUTE_FILTERS` with default 5
- [ ] 1.3 Document the env var in the project README's "Configuration" section

## 2. Listener

- [ ] 2.1 In `App\Read\Http\ReadResponseConventionsListener`: replace the "at most one `attribute.<key>` filter per request" branch with a count-and-cap check using the configured maximum
- [ ] 2.2 Detect "same attribute key repeated" and reject with the existing "repeated parameter" 400 message naming the offending key
- [ ] 2.3 The 400 message for over-cap reads: "At most %d attribute.<key> filters per request" (interpolate the configured cap)
- [ ] 2.4 Inject the cap into the listener via constructor argument bound to `%crashler.read.max_attribute_filters%`

## 3. State providers

- [ ] 3.1 In `App\Read\State\LogsStateProvider`: replace the single-attribute extraction with a loop over the raw query string that emits one `JsonAttributeEquals('attributes_json', $key, $value)` predicate per `attribute.<key>=<value>` pair
- [ ] 3.2 Same change in `App\Read\State\TracesStateProvider`
- [ ] 3.3 Same change in `App\Read\State\MetricsStateProvider`
- [ ] 3.4 Confirm `BaseSearchStateProvider` (or its equivalent) still passes the predicates list through unchanged

## 4. Tests

- [ ] 4.1 Update `HttpConventionsTest::testMultipleAttributeFiltersInOneRequestRejected` (or its equivalent): replace with a unit test using `Request::create()` that asserts the count cap (six rejected) instead of "more than one rejected"
- [ ] 4.2 Add a unit test that asserts five distinct `attribute.<key>` filters are accepted (does not 400) by the listener
- [ ] 4.3 Add a unit test that asserts repeated *same* attribute key returns 400 with the "repeated parameter" message naming the key
- [ ] 4.4 Add a functional test under `tests/Functional/Read/`: write a fixture with rows whose `attributesJson` contains both `exception.type=RuntimeException` and `http.method=POST`, plus rows that match only one; assert that a `?attribute.exception.type=RuntimeException&attribute.http.method=POST&since=…` request returns only the rows matching both
- [ ] 4.5 Add a similar functional test for traces and metrics
- [ ] 4.6 Verify all existing read-API functional tests continue to pass

## 5. Documentation

- [ ] 5.1 Update the project README's "Searching attributes" subsection: drop the "one attribute key per request" caveat, document the cap, document AND-only composition
- [ ] 5.2 Mention in the README that multiple-value-per-key (`?attribute.k=a&attribute.k=b`) remains a 400 — point operators at the future POST search endpoint
