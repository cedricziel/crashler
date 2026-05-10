<?php

declare(strict_types=1);

namespace App\Explorer;

use App\Read\Compute\AggregatingScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Criteria\TimeWindow;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Resolves the top-N distinct values for a given parquet column inside the
 * current time window. Drives the `<datalist>` autocomplete on every text-
 * kind FilterDefinition that declares a parquet column.
 *
 * Implementation: a `count` aggregation grouped by the column. Sorted by
 * frequency descending, capped at `$maxValues`. AggregatingScanner already
 * caps cardinality at 200 by default — values past that hard-fail the scan,
 * but we tolerate that and return whatever we got via try/catch.
 */
final readonly class AutocompleteResolver
{
    public function __construct(
        private AggregatingScanner $scanner,
        private PartitionPruner $pruner,
        private WindowBucket $bucket,
        private CacheInterface $cache,
        private int $maxValues = 50,
        private int $cacheTtlSeconds = 60,
    ) {
    }

    /**
     * @return list<string> ordered by frequency desc, distinct, never-null
     */
    public function topValues(string $tenantSlug, string $signal, string $parquetColumn, TimeWindow $window): array
    {
        $window = $this->bucket->snap($window);
        $globs = $this->pruner->globsFor($tenantSlug, $signal, $window);

        $cacheKey = \sprintf(
            'explorer.ac.%s.%s.%s.%d.%d',
            $tenantSlug,
            $signal,
            $parquetColumn,
            $window->sinceUnixNano,
            $window->untilUnixNano,
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($globs, $signal, $parquetColumn, $window): array {
            $item->expiresAfter($this->cacheTtlSeconds);

            $timeColumn = 'traces' === $signal ? 'start_time_unix_nano' : 'time_unix_nano';
            $predicates = [new ColumnInRange($timeColumn, $window->sinceUnixNano, $window->untilUnixNano)];

            try {
                $result = $this->scanner->aggregate($globs, $predicates, 'count', null, $parquetColumn);
            } catch (\Throwable) {
                return [];
            }

            $values = [];
            foreach ($result->rows as $row) {
                $count = $row['value'] ?? 0;
                $group = $row['group'] ?? null;
                if (!\is_array($group)) {
                    continue;
                }
                $value = $group[$parquetColumn] ?? null;
                if (!\is_string($value) || '' === $value) {
                    continue;
                }
                $values[$value] = \is_int($count) ? $count : 0;
            }

            arsort($values);

            return \array_slice(array_keys($values), 0, $this->maxValues);
        });
    }
}
