<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\SignalProfileRegistry;
use App\Explorer\TableResultResolver;
use App\Read\Criteria\TimeWindow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Deferred result table. Initial render shows skeleton rows;
 * hydration runs the ParquetScanner and emits the populated tbody.
 *
 * Cursor pagination is in-component: `nextPage` and `prevPage`
 * LiveActions push/pop opaque position cursors; the resolver keys
 * its cache by (tenant, signal, window, cursor) so navigating back
 * to a page already visited in this session is instant.
 */
#[AsLiveComponent('Explorer:ResultTable', template: 'components/explorer/result_table.html.twig')]
final class ResultTable
{
    use DefaultActionTrait;

    /** Hard cap on cursor history depth — beyond this users should narrow filters. */
    private const int MAX_HISTORY = 50;

    #[LiveProp]
    public string $tenantSlug = '';

    #[LiveProp]
    public string $signal = '';

    #[LiveProp]
    public int $windowSinceNs = 0;

    #[LiveProp]
    public int $windowUntilNs = 0;

    /** @var ?array{lastTimeUnixNano: int, lastRowId: int} */
    #[LiveProp(writable: true)]
    public ?array $cursor = null;

    /**
     * Past cursors so prevPage can walk backwards.
     *
     * @var list<array{lastTimeUnixNano: int, lastRowId: int}|null>
     */
    #[LiveProp(writable: true)]
    public array $history = [];

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
     * @return array{rows: list<array<string, mixed>>, nextCursor: ?array{lastTimeUnixNano: int, lastRowId: int}}
     */
    public function page(): array
    {
        if ('' === $this->tenantSlug || 0 === $this->windowUntilNs) {
            return ['rows' => [], 'nextCursor' => null];
        }
        $window = new TimeWindow($this->windowSinceNs, $this->windowUntilNs);

        return $this->resolver->page($this->tenantSlug, $this->signal, $window, $this->cursor);
    }

    public function pageNumber(): int
    {
        return \count($this->history) + 1;
    }

    public function hasPrev(): bool
    {
        return [] !== $this->history;
    }

    #[LiveAction]
    public function nextPage(): void
    {
        $page = $this->page();
        if (null === $page['nextCursor']) {
            return;
        }
        if (\count($this->history) >= self::MAX_HISTORY) {
            // Silently cap. The UI can still display the page; we just
            // don't track it for prev navigation past the cap.
            return;
        }
        $this->history[] = $this->cursor;
        $this->cursor = $page['nextCursor'];
    }

    #[LiveAction]
    public function prevPage(): void
    {
        if ([] === $this->history) {
            return;
        }
        $this->cursor = array_pop($this->history);
    }
}
