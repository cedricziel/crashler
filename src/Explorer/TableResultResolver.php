<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Criteria\TimeWindow;

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
        private int $defaultPageSize = 50,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function firstPage(string $tenantSlug, string $signal, TimeWindow $window): array
    {
        $globs = $this->pruner->globsFor($tenantSlug, $signal, $window);
        $timeColumn = 'traces' === $signal ? 'start_time_unix_nano' : 'time_unix_nano';
        $predicates = [new ColumnInRange($timeColumn, $window->sinceUnixNano, $window->untilUnixNano)];

        try {
            $result = $this->scanner->scan($globs, $predicates, $this->defaultPageSize);
        } catch (\Throwable) {
            return [];
        }

        return $result->rows;
    }
}
