<?php

declare(strict_types=1);

namespace App\Read\Compute;

use App\Read\Compute\Predicates\Predicate;
use Flow\Parquet\Reader;
use Psr\Clock\ClockInterface;

/**
 * Streaming Parquet scanner — the only compute engine for the read API.
 *
 * For each `<storage-root>/<signal>/<tenant>/date=…/hour=…/part-<ulid>.parquet`
 * partition file (ULID-ordered, oldest first), opens a flow-php `Reader`,
 * iterates rows, and applies the supplied predicates in tier order
 * (cheap-first, short-circuit on first failure). Stops early once `limit`
 * rows have been collected. Wall-clock timeout enforced between rows.
 *
 * Errors:
 *  - {@see ScanTimeoutException} when execution exceeds the timeout.
 *  - {@see ScanIoException} when a Parquet file is corrupted or unreadable.
 *    Both carry operator-friendly messages with absolute paths masked.
 *
 * The scanner owns ULID ordering, predicate sorting, position tracking,
 * and the "more results exist" signal. It does NOT own:
 *  - Time-window resolution (see {@see TimeWindow}, supplied as a predicate)
 *  - Partition glob computation (see {@see PartitionPruner})
 *  - Pagination cursor encoding (see {@see \App\Read\Cursor\Cursor})
 */
final readonly class ParquetScanner
{
    public function __construct(
        private ClockInterface $clock,
        private int $executionTimeoutSeconds,
    ) {
    }

    /**
     * @param list<string>    $partitionGlobs glob patterns produced by {@see PartitionPruner}
     * @param list<Predicate> $predicates     evaluated in tier order; ALL must pass for a row to match
     * @param ?array{lastTimeUnixNano: int, lastRowId: int} $resumeFrom resume position from a previous cursor; rows at or before this position are skipped
     */
    public function scan(array $partitionGlobs, array $predicates, int $limit, ?array $resumeFrom = null): ScanResult
    {
        // Tier order — cheap predicates first so wide queries fail-fast on
        // the cheap conditions before paying the JSON-decode cost on the
        // expensive ones (see design.md D12).
        usort($predicates, static fn (Predicate $a, Predicate $b): int => $a->tier() <=> $b->tier());

        $deadline = $this->clock->now()->getTimestamp() + $this->executionTimeoutSeconds;

        $files = $this->resolveFiles($partitionGlobs);

        $rows = [];
        $lastPosition = null;
        $hasMore = false;
        $rowsCollected = 0;

        $reader = new Reader();
        foreach ($files as $file) {
            try {
                $parquetFile = $reader->read($file);
            } catch (\Throwable $e) {
                throw new ScanIoException(
                    \sprintf('Failed to read Parquet file %s: %s', basename($file), $e->getMessage()),
                    previous: $e,
                );
            }

            $rowIdInFile = -1;
            try {
                foreach ($parquetFile->values() as $row) {
                    ++$rowIdInFile;

                    // Wall-clock timeout check (cheap — once per row, not
                    // once per predicate). PHP's set_time_limit is
                    // unreliable in mod_php; explicit check is robust.
                    if ($this->clock->now()->getTimestamp() >= $deadline) {
                        throw new ScanTimeoutException(\sprintf(
                            'Read exceeded the configured execution timeout of %d seconds. Narrow your filters or reduce the time window.',
                            $this->executionTimeoutSeconds,
                        ));
                    }

                    // Resume-from check: skip rows up to and including the
                    // previous cursor position.
                    if (null !== $resumeFrom && self::rowAtOrBeforePosition($row, $rowIdInFile, $resumeFrom)) {
                        continue;
                    }

                    if (!self::rowMatches($row, $predicates)) {
                        continue;
                    }

                    if ($rowsCollected === $limit) {
                        // We already had `limit` rows; finding one more
                        // proves more exist beyond the page boundary.
                        $hasMore = true;
                        break 2;
                    }

                    $rows[] = $row;
                    ++$rowsCollected;

                    $lastPosition = [
                        'lastTimeUnixNano' => self::extractTimeUnixNano($row),
                        'lastRowId' => $rowIdInFile,
                    ];
                }
            } catch (ScanTimeoutException $e) {
                throw $e;
            } catch (ScanIoException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ScanIoException(
                    \sprintf('Failed reading rows from %s: %s', basename($file), $e->getMessage()),
                    previous: $e,
                );
            }
        }

        return new ScanResult($rows, $lastPosition, $hasMore);
    }

    /**
     * @param list<string> $partitionGlobs
     *
     * @return list<string> file paths in ULID-ascending order
     */
    private function resolveFiles(array $partitionGlobs): array
    {
        $files = [];
        foreach ($partitionGlobs as $glob) {
            $matches = glob($glob, \GLOB_NOSORT);
            if (false === $matches || [] === $matches) {
                continue;
            }
            foreach ($matches as $match) {
                $files[] = $match;
            }
        }

        // ULID-ascending = lexicographic (ULIDs are time-sortable strings).
        // Within a partition that's chronological; across partitions the
        // glob order from PartitionPruner is already chronological so any
        // ULID ordering inside a partition is consistent.
        sort($files);

        return $files;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Predicate>      $predicates already in tier order
     */
    private static function rowMatches(array $row, array $predicates): bool
    {
        foreach ($predicates as $predicate) {
            if (!$predicate->evaluate($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>                       $row
     * @param array{lastTimeUnixNano: int, lastRowId: int} $resumeFrom
     */
    private static function rowAtOrBeforePosition(array $row, int $rowIdInFile, array $resumeFrom): bool
    {
        $time = self::extractTimeUnixNano($row);
        if ($time < $resumeFrom['lastTimeUnixNano']) {
            return true;
        }
        if ($time > $resumeFrom['lastTimeUnixNano']) {
            return false;
        }

        return $rowIdInFile <= $resumeFrom['lastRowId'];
    }

    /**
     * Pulls the time value from whichever signal-specific column carries it.
     * Logs and metrics use `time_unix_nano`; traces use `start_time_unix_nano`.
     *
     * @param array<string, mixed> $row
     */
    private static function extractTimeUnixNano(array $row): int
    {
        if (isset($row['time_unix_nano']) && \is_int($row['time_unix_nano'])) {
            return $row['time_unix_nano'];
        }
        if (isset($row['start_time_unix_nano']) && \is_int($row['start_time_unix_nano'])) {
            return $row['start_time_unix_nano'];
        }

        return 0;
    }
}
