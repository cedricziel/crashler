<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\KpiBundleResolver;
use App\Explorer\KpiValue;
use App\Explorer\SignalProfileRegistry;
use App\Read\Criteria\TimeWindow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Deferred KPI strip. The initial server render shows five skeleton
 * tiles immediately; the browser then issues a single Live re-render
 * request which actually runs the aggregate scans and populates the
 * tiles. The page's perceived load time stops being bound by the
 * KPI scan time.
 *
 * Sibling sections (Chart, ResultTable) defer the same way, so the
 * browser parallelises all three hydration requests.
 */
#[AsLiveComponent('Explorer:KpiStrip', template: 'components/explorer/kpi_strip.html.twig')]
final class KpiStrip
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $tenantSlug = '';

    #[LiveProp]
    public string $signal = '';

    #[LiveProp]
    public int $windowSinceNs = 0;

    #[LiveProp]
    public int $windowUntilNs = 0;

    public function __construct(
        private readonly KpiBundleResolver $resolver,
        private readonly SignalProfileRegistry $profiles,
    ) {
    }

    /**
     * @return list<\App\Explorer\KpiSpec>
     */
    public function kpis(): array
    {
        return $this->profiles->get($this->signal)->kpis();
    }

    /**
     * @return list<KpiValue>
     */
    public function values(): array
    {
        $profile = $this->profiles->get($this->signal);
        $window = new TimeWindow($this->windowSinceNs, $this->windowUntilNs);

        return $this->resolver->resolve($this->tenantSlug, $this->signal, $profile->kpis(), $window);
    }
}
