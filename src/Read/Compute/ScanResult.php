<?php

declare(strict_types=1);

namespace App\Read\Compute;

/**
 * Result of one read scan: the rows, plus the position marker that becomes
 * the next page's cursor, plus a flag indicating whether more rows exist
 * beyond the limit.
 *
 * `groupsScanned` / `groupsSkipped` are observability counters set by the
 * scanner's Tier-1 row-group push-down (see {@see ParquetScanner}). They are
 * surfaced for component tests and structured logging; they are NOT exposed
 * in the HTTP response body.
 */
final readonly class ScanResult
{
    /**
     * @param list<array<string, mixed>>                    $rows
     * @param ?array{lastTimeUnixNano: int, lastRowId: int} $position
     */
    public function __construct(
        public array $rows,
        public ?array $position,
        public bool $hasMore,
        public int $groupsScanned = 0,
        public int $groupsSkipped = 0,
    ) {
    }
}
