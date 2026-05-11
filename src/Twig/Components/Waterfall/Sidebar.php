<?php

declare(strict_types=1);

namespace App\Twig\Components\Waterfall;

use App\Explorer\TraceWaterfallResolver;
use App\Read\Criteria\TimeWindow;
use Psr\Clock\ClockInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Right-hand 20% panel of the waterfall view. Empty until the user
 * clicks a span row in the tree on the left; that fires the
 * `selectSpan(spanId)` LiveAction which re-renders just this panel
 * with the selected span's attributes / status / events / resource.
 *
 * The Live re-render is server-side, so collapse-state for the
 * sub-sections is preserved across selections (when we add it).
 *
 * Cross-trace defense: even though the LiveProp `traceId` is a hint
 * from the page, the resolver's predicate set always pins both
 * `trace_id_hex` AND `span_id_hex` — a forged span id from a
 * different trace simply returns no rows and renders the empty state.
 */
#[AsLiveComponent('Waterfall:Sidebar', template: 'components/waterfall/sidebar.html.twig')]
final class Sidebar
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $tenantSlug = '';

    #[LiveProp]
    public string $traceId = '';

    /**
     * The waterfall page's resolved window — shadowed onto the sidebar so
     * span-detail lookups search inside the same range the parent page
     * used to resolve the tree. Without this the sidebar falls back to
     * the 24h `spanLookupWindowHours` default and 404s every span click
     * for any trace older than yesterday.
     */
    #[LiveProp]
    public int $windowSinceNs = 0;

    #[LiveProp]
    public int $windowUntilNs = 0;

    #[LiveProp(writable: true)]
    public ?string $selectedSpanId = null;

    public function __construct(
        private readonly TraceWaterfallResolver $resolver,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return ?array<string, mixed>
     */
    public function span(): ?array
    {
        if (null === $this->selectedSpanId || '' === $this->tenantSlug || '' === $this->traceId) {
            return null;
        }

        $window = $this->windowSinceNs > 0 && $this->windowUntilNs > $this->windowSinceNs
            ? new TimeWindow($this->windowSinceNs, $this->windowUntilNs)
            : TimeWindow::parse(
                ['since' => $this->resolver->spanLookupWindowHours().'h'],
                $this->clock,
                30,
            );

        return $this->resolver->span($this->tenantSlug, $this->traceId, $this->selectedSpanId, $window);
    }

    public function logsDrillUrl(): ?string
    {
        $span = $this->span();
        if (null === $span) {
            return null;
        }
        // ±5s either side of the span's window.
        $sinceNs = $span['startNs'] - 5_000_000_000;
        $untilNs = $span['endNs'] + 5_000_000_000;

        return \sprintf(
            '/tenants/%s/explore/logs?since=%s&until=%s&traceId=%s',
            urlencode($this->tenantSlug),
            urlencode((string) $sinceNs),
            urlencode((string) $untilNs),
            urlencode($this->traceId),
        );
    }

    #[LiveAction]
    public function selectSpan(#[LiveArg] string $spanId): void
    {
        // Only set the spanId; the next render's span() method does the
        // resolver lookup. Bogus ids just produce a null span and the
        // sidebar shows the empty state again.
        $this->selectedSpanId = $spanId;
    }
}
