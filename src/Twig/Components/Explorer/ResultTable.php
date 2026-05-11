<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\SignalProfileRegistry;
use App\Explorer\TableResultResolver;
use App\Read\Criteria\TimeWindow;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * Normalises a parquet `trace_id_hex` / `span_id_hex` cell to lowercase
     * hex. Returns null when the value isn't a usable id.
     *
     * Writer convention is hex strings (see LogsIngestService /
     * TracesIngestService), but legacy partitions may carry raw bytes —
     * mirrors {@see App\Read\State\BaseSearchStateProvider::bytesToHex}.
     */
    public function toHex(mixed $raw): ?string
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return 1 === preg_match('/^[0-9a-f]+$/', $raw) ? $raw : bin2hex($raw);
    }

    /**
     * Builds `/tenants/{slug}/traces/{traceId}` for the cell value, or
     * null when the value can't be normalised to the 32-hex-char shape
     * the waterfall route requires.
     */
    public function traceUrl(mixed $raw): ?string
    {
        $hex = $this->toHex($raw);
        if (null === $hex || 32 !== \strlen($hex) || '' === $this->tenantSlug) {
            return null;
        }

        return $this->router->generate('app_trace_waterfall', [
            'slug' => $this->tenantSlug,
            'traceId' => $hex,
        ]);
    }

    /**
     * For a `span_id_hex` cell, links to the waterfall page of the row's
     * owning trace (there is no standalone span detail page; the waterfall
     * sidebar is the span detail surface). Returns null when the row has
     * no usable `trace_id_hex`.
     *
     * @param array<string, mixed> $row
     */
    public function spanTraceUrl(array $row): ?string
    {
        return $this->traceUrl($row['trace_id_hex'] ?? null);
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
