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
 * Tier-1 push-down: BEFORE iterating rows the scanner reads each file's
 * row-group metadata via `ParquetFile::metadata()->rowGroups()` and applies
 * {@see RowGroupSkipper} to refute groups against the active numeric
 * predicates. Skipped groups are coalesced into runs and only the surviving
 * runs are passed to flow-php's `values(offset, limit)` so their data pages
 * are never opened. `groupsScanned` / `groupsSkipped` on the returned
 * {@see ScanResult} expose the skip count for tests and structured logging.
 *
 * Errors:
 *  - {@see ScanTimeoutException} when execution exceeds the timeout.
 *  - {@see ScanIoException} when a Parquet file is corrupted or unreadable.
 *    Both carry operator-friendly messages with absolute paths masked.
 */
final readonly class ParquetScanner
{
    public function __construct(
        private ClockInterface $clock,
        private int $executionTimeoutSeconds,
        private RowGroupSkipper $rowGroupSkipper = new RowGroupSkipper(),
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
        $groupsScanned = 0;
        $groupsSkipped = 0;

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

            try {
                $rowGroups = $parquetFile->metadata()->rowGroups()->all();
                $schema = $parquetFile->schema();
            } catch (\Throwable $e) {
                throw new ScanIoException(
                    \sprintf('Failed to read row-group metadata from %s: %s', basename($file), $e->getMessage()),
                    previous: $e,
                );
            }

            // Classify each row group: skip-or-scan based on per-group stats.
            // Coalesce contiguous scan-runs so we can read each surviving run
            // via a single `values(offset, limit)` call.
            $runs = [];
            $cumulativeOffset = 0;
            $currentRunStart = null;
            $currentRunCount = 0;
            foreach ($rowGroups as $group) {
                $rowsInGroup = $group->rowsCount();
                if ($this->rowGroupSkipper->canSkip($group, $schema, $predicates)) {
                    ++$groupsSkipped;
                    if (null !== $currentRunStart) {
                        $runs[] = ['offset' => $currentRunStart, 'count' => $currentRunCount];
                        $currentRunStart = null;
                        $currentRunCount = 0;
                    }
                } else {
                    ++$groupsScanned;
                    if (null === $currentRunStart) {
                        $currentRunStart = $cumulativeOffset;
                    }
                    $currentRunCount += $rowsInGroup;
                }
                $cumulativeOffset += $rowsInGroup;
            }
            if (null !== $currentRunStart) {
                $runs[] = ['offset' => $currentRunStart, 'count' => $currentRunCount];
            }
            if ([] === $runs) {
                continue; // entire file refuted by metadata — nothing to scan
            }

            try {
                foreach ($runs as $run) {
                    if ($this->scanRun(
                        $parquetFile,
                        $file,
                        $run['offset'],
                        $run['count'],
                        $predicates,
                        $limit,
                        $resumeFrom,
                        $deadline,
                        $rows,
                        $lastPosition,
                        $rowsCollected,
                    )) {
                        $hasMore = true;
                        break 2;
                    }
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

        return new ScanResult($rows, $lastPosition, $hasMore, $groupsScanned, $groupsSkipped);
    }

    /**
     * Scans one contiguous run of row groups inside a Parquet file.
     *
     * Returns `true` if the limit was reached and there's at least one more
     * matching row beyond it (signalling `hasMore`). Returns `false` when
     * the run is exhausted without exceeding the limit.
     *
     * @param list<Predicate>                              $predicates
     * @param ?array{lastTimeUnixNano: int, lastRowId: int} $resumeFrom
     * @param list<array<string, mixed>>                   $rows           (mutated)
     * @param ?array{lastTimeUnixNano: int, lastRowId: int} $lastPosition (mutated)
     */
    private function scanRun(
        \Flow\Parquet\ParquetFile $parquetFile,
        string $file,
        int $runOffset,
        int $runCount,
        array $predicates,
        int $limit,
        ?array $resumeFrom,
        int $deadline,
        array &$rows,
        ?array &$lastPosition,
        int &$rowsCollected,
    ): bool {
        try {
            $iter = $parquetFile->values(columns: [], limit: $runCount, offset: $runOffset);
        } catch (\Throwable $e) {
            throw new ScanIoException(
                \sprintf('Failed reading rows from %s: %s', basename($file), $e->getMessage()),
                previous: $e,
            );
        }

        $rowIdInFile = $runOffset - 1;
        foreach ($iter as $row) {
            ++$rowIdInFile;

            if ($this->clock->now()->getTimestamp() >= $deadline) {
                throw new ScanTimeoutException(\sprintf(
                    'Read exceeded the configured execution timeout of %d seconds. Narrow your filters or reduce the time window.',
                    $this->executionTimeoutSeconds,
                ));
            }

            if (null !== $resumeFrom && self::rowAtOrBeforePosition($row, $rowIdInFile, $resumeFrom)) {
                continue;
            }

            if (!self::rowMatches($row, $predicates)) {
                continue;
            }

            if ($rowsCollected === $limit) {
                return true; // hasMore
            }

            $rows[] = $row;
            ++$rowsCollected;

            $lastPosition = [
                'lastTimeUnixNano' => self::extractTimeUnixNano($row),
                'lastRowId' => $rowIdInFile,
            ];
        }

        return false;
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
