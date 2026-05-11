# Tasks — `enhance-waterfall-detail`

TDD-ordered. Each implementation task has a sibling test task above it (or notes when a test covers two implementation tasks).

## P0 — bug fixes (everything below depends on these)

- [x] **1.1** Test: `tests/Component/Waterfall/SidebarComponentTest::testSpanLookupUsesInheritedWindowNotDefault24h` — hydrate the Sidebar with `windowSinceNs` / `windowUntilNs` pointing 3 days ago; assert `selectSpan` resolves a 3-day-old span (would 404 under the 24h default).
- [x] **1.2** Implement: `Sidebar` gains `windowSinceNs` / `windowUntilNs` LiveProps; `span()` builds its `TimeWindow` from them; `WaterfallController` passes them through to the `<twig:Waterfall:Sidebar>` mount.
- [x] **2.1** Test: `tests/Functional/Waterfall/WaterfallAccessTest::testSelectedRowCarriesAriaSelectedTrue` — assert the root row's `aria-selected="true"` is in the rendered HTML.
- [x] **2.2** Implement: page template sets `aria-selected` per row from the `selectedSpanId` view-var.

## P1 — root preselect + permalink

- [x] **3.1** Test: `WaterfallAccessTest::testRootSpanIsPreselectedOnInitialPaint` — sidebar renders the root span's attributes on the first page response (no click).
- [x] **3.2** Test: `WaterfallAccessTest::testSpanIdQueryParamPreselectsThatSpan` — `?spanId=…` overrides the root preselect; that row gets `aria-selected="true"`.
- [x] **3.3** Implement: `TraceWaterfallResolver::resolve()` exposes `rootSpanId` in its return shape; controller resolves the `selectedSpanId` (URL query → root fallback) and passes both `rootSpanId` and `selectedSpanId` to the template; template uses `selectedSpanId` for the Sidebar mount + per-row `aria-selected`.

## P1 — error polish

- [x] **4.1** Test: `WaterfallAccessTest::testHeaderSurfacesErrorJumpAffordanceWhenAnySpanErrored` — seed a trace with one `statusCode=2` span; assert the header contains "1 error" and a `data-jump-target` link.
- [x] **4.2** Test: `WaterfallAccessTest::testErroredSpanCarriesStripeAndGlyph` — assert the errored row contains the `⚠` glyph and a CSS class flagging the bar stripe.
- [x] **4.3** Implement: row template emits `⚠` glyph when `span.statusCode == 2`; bar gains an `.span-row__bar--error-stripe` class; header counts errors and renders the jump-to-first link; resolver exposes `firstErrorSpanId` so the link's `href="#span-…"` can land precisely.

## P2 — defer sidebar + column projection

- [x] **5.1** Test: `tests/Component/Read/ParquetScannerTest::testScanProjectsToRequestedColumnsOnly` — scan with `columns: ['span_id_hex', 'name']`; assert the returned rows have those two keys and only those two.
- [x] **5.2** Implement: `ParquetScanner::scan()` gains a `columns: list<string> = []` parameter; passes through to `parquetFile->values(columns: …)`.
- [x] **6.1** Test: `tests/Component/Waterfall/TraceWaterfallResolverTest::testResolveDoesNotReadAttributeJsonColumns` — assert returned `spans` carry no `attributes_json` etc.
- [x] **6.2** Implement: `TraceWaterfallResolver::resolve()` requests the 9-column whitelist; `Sidebar::span()` continues full-column scan.

## P2 — color by kind

- [x] **7.1** Test: `TraceWaterfallResolverTest::testShapeSpanExposesSpanKind` — shaped span includes `kind` (0..5).
- [x] **7.2** Test: functional — render a trace whose spans span 3 different kinds; assert the bar's `data-kind` attribute reflects each.
- [x] **7.3** Implement: resolver adds `kind` to the shaped span; page template emits `data-kind="…"` on each bar; CSS palette maps `[data-kind="N"]` to its color; an inline legend strip renders above the axis.

## P3 — minimap

- [x] **8.1** Test: `tests/Component/Waterfall/MinimapComponentTest::testRendersOneBarPerSpan` — passive component test with stub spans; assert N mini-bars, kind-color data attribute preserved.
- [x] **8.2** Test: `MinimapComponentTest::testViewportControllerWiringIsPresent` — assert the rendered HTML carries `data-controller="minimap"` + `data-minimap-tree-selector-value`.
- [x] **8.3** Implement: `App\Twig\Components\Waterfall\Minimap` passive component + `templates/components/waterfall/minimap.html.twig`.
- [x] **8.4** Implement: `assets/controllers/minimap_controller.js` (drag-to-scroll, rAF-coalesced viewport sync).
- [x] **8.5** Manual smoke test on production after deploy: scroll a long trace, drag the minimap viewport, click a span.

## Quality gates

- [x] **9.1** `composer cs:fix` + `composer phpstan` clean on every commit.
- [x] **9.2** `composer coverage:gate` stays ≥80%.
- [ ] **9.3** Deploy to production via `dep deploy stage=production` once all P0/P1/P2 are merged; minimap (P3) may ship in the same deploy or a follow-up.
