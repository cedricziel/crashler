<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\SignalProfileRegistry;
use App\Explorer\TableResultResolver;
use App\Read\Criteria\TimeWindow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Deferred result table. Initial render shows skeleton rows;
 * hydration runs the ParquetScanner and emits the populated tbody.
 */
#[AsLiveComponent('Explorer:ResultTable', template: 'components/explorer/result_table.html.twig')]
final class ResultTable
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
        private readonly TableResultResolver $resolver,
        private readonly SignalProfileRegistry $profiles,
    ) {
    }

    /**
     * @return list<\App\Explorer\TableColumn>
     */
    public function columns(): array
    {
        return $this->profiles->get($this->signal)->tableColumns();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ('' === $this->tenantSlug || 0 === $this->windowUntilNs) {
            return [];
        }
        $window = new TimeWindow($this->windowSinceNs, $this->windowUntilNs);

        return $this->resolver->firstPage($this->tenantSlug, $this->signal, $window);
    }
}
