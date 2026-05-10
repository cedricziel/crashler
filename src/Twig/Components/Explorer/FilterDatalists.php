<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\AutocompleteResolver;
use App\Explorer\SignalProfileRegistry;
use App\Read\Criteria\TimeWindow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Deferred filter autocomplete. Renders nothing on the initial paint
 * (the form input fields work without a datalist). Once hydrated,
 * the component runs an aggregate-count-by-column scan per text-kind
 * filter and renders <datalist> elements that the form inputs pick
 * up by `list=` attribute reference (datalists can be elsewhere in
 * the document).
 */
#[AsLiveComponent('Explorer:FilterDatalists', template: 'components/explorer/filter_datalists.html.twig')]
final class FilterDatalists
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
        private readonly AutocompleteResolver $resolver,
        private readonly SignalProfileRegistry $profiles,
    ) {
    }

    /**
     * @return array<string, list<string>>
     */
    public function suggestions(): array
    {
        if ('' === $this->tenantSlug || 0 === $this->windowUntilNs) {
            return [];
        }

        $profile = $this->profiles->get($this->signal);
        $window = new TimeWindow($this->windowSinceNs, $this->windowUntilNs);

        $out = [];
        foreach ($profile->filters() as $filter) {
            if (!$filter->shouldAutocomplete()) {
                if ([] !== $filter->suggestions) {
                    $out[$filter->key] = $filter->suggestions;
                }
                continue;
            }
            $values = $this->resolver->topValues($this->tenantSlug, $this->signal, $filter->parquetColumn, $window);
            $merged = array_values(array_unique(array_merge($filter->suggestions, $values)));
            if ([] !== $merged) {
                $out[$filter->key] = $merged;
            }
        }

        return $out;
    }
}
