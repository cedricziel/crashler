<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Criteria\TimeWindow;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Resolves the first page of rows for the explorer's results table.
 *
 * Calls the same `ParquetScanner` the read API uses, with the same time-
 * column predicate applied. Cursor pagination is intentionally NOT
 * implemented here — for the explorer page we always render the first
 * page server-side. Subsequent page navigation goes through the read
 * API's cursor flow via the table_controller.js Stimulus controller.
 */
final readonly class TableResultResolver
{
    public function __construct(
        private ParquetScanner $scanner,
        private PartitionPruner $pruner,
        private WindowBucket $bucket,
        private CacheInterface $cache,
        private int $defaultPageSize = 50,
        private int $cacheTtlSeconds = 60,
    ) {
    }

    /**
     * Returns the first page of rows for the explorer's results table.
     *
     * @return list<array<string, mixed>>
     */
    public function firstPage(string $tenantSlug, string $signal, TimeWindow $window): array
    {
        return $this->page($tenantSlug, $signal, $window, null)['rows'];
    }

    /**
     * Returns one page of rows + the cursor for the next page (or null
     * if no more rows exist beyond this page).
     *
     * The cursor is the raw `{lastTimeUnixNano, lastRowId}` position
     * array from ParquetScanner — opaque-ish, but used in-process only.
     * No HMAC signing here (the read API's cursor signing is for
     * external clients).
     *
     * @param ?array{lastTimeUnixNano: int, lastRowId: int} $cursor
     *
     * @return array{rows: list<array<string, mixed>>, nextCursor: ?array{lastTimeUnixNano: int, lastRowId: int}}
     */
    public function page(string $tenantSlug, string $signal, TimeWindow $window, ?array $cursor): array
    {
        $window = $this->bucket->snap($window);
        $globs = $this->pruner->globsFor($tenantSlug, $signal, $window);

        $cacheKey = \sprintf(
            'explorer.table.%s.%s.%d.%d.%s',
            $tenantSlug,
            $signal,
            $window->sinceUnixNano,
            $window->untilUnixNano,
            null === $cursor ? '0' : $cursor['lastTimeUnixNano'].'-'.$cursor['lastRowId'],
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($globs, $signal, $window, $cursor): array {
            $item->expiresAfter($this->cacheTtlSeconds);

            $timeColumn = 'traces' === $signal ? 'start_time_unix_nano' : 'time_unix_nano';
            $predicates = [new ColumnInRange($timeColumn, $window->sinceUnixNano, $window->untilUnixNano)];

            try {
                $result = $this->scanner->scan($globs, $predicates, $this->defaultPageSize, $cursor);
            } catch (\Throwable) {
                return ['rows' => [], 'nextCursor' => null];
            }

            return [
                'rows' => $result->rows,
                'nextCursor' => $result->hasMore ? $result->position : null,
            ];
        });
    }
}
