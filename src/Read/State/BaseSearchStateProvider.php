<?php

declare(strict_types=1);

namespace App\Read\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Compute\Predicates\Predicate;
use App\Read\Criteria\TimeWindow;
use App\Read\Cursor\Cursor;
use App\Read\Cursor\InvalidCursorException;
use App\Security\IngestUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Shared scaffolding for the per-signal collection state providers.
 *
 * Concrete subclasses implement three hooks:
 *
 *   1. {@see signalSubdir} — the filesystem subdirectory under storage root
 *      ('logs', 'traces', or 'metrics').
 *   2. {@see compilePerSignalPredicates} — the per-signal filter →
 *      {@see Predicate} compilation.
 *   3. {@see rowToResource} — materialise a Parquet row map into the
 *      Resource DTO that AP serializes.
 *
 * The base class owns:
 *   - Cursor decoding (when `?cursor=...` is supplied) and tenant scope
 *     enforcement.
 *   - Time-window parsing with the configured cap and the default 1-hour
 *     fallback.
 *   - `limit` validation against `max_page_size`.
 *   - The common predicates (time window, service, environment, host)
 *     that every signal supports.
 *   - Dispatching to the streaming `ParquetScanner` and feeding the
 *     resulting rows through `rowToResource`.
 */
abstract readonly class BaseSearchStateProvider implements ProviderInterface
{
    public function __construct(
        protected ParquetScanner $scanner,
        protected PartitionPruner $pruner,
        protected Security $security,
        protected ClockInterface $clock,
        protected int $maxTimeWindowDays,
        protected int $maxPageSize,
        protected string $cursorSecret,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $request = $context['request'] ?? null;
        if (null === $request) {
            return [];
        }

        $user = $this->security->getUser();
        if (!$user instanceof IngestUser) {
            return [];
        }
        $tenantSlug = $user->tenant->slug;

        $query = $request->query->all();
        $cursorValue = $query['cursor'] ?? null;

        if (\is_string($cursorValue) && '' !== $cursorValue) {
            try {
                $cursor = Cursor::decode($cursorValue, $this->cursorSecret, $tenantSlug, $this->maxTimeWindowDays);
            } catch (InvalidCursorException $e) {
                throw new BadRequestException($e->getMessage(), previous: $e);
            }
            $criteria = $cursor->criteria;
            $resumeFrom = $cursor->position;
        } else {
            $criteria = $query;
            $resumeFrom = null;
        }

        try {
            $window = TimeWindow::parse(
                ['since' => $criteria['since'] ?? null, 'until' => $criteria['until'] ?? null],
                $this->clock,
                $this->maxTimeWindowDays,
            );
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            throw new BadRequestException($e->getMessage(), previous: $e);
        }

        $limit = isset($criteria['limit']) ? (int) $criteria['limit'] : 100;
        if ($limit < 1) {
            throw new BadRequestException('`limit` must be a positive integer.');
        }
        if ($limit > $this->maxPageSize) {
            throw new BadRequestException(\sprintf('`limit` exceeds max_page_size (%d).', $this->maxPageSize));
        }

        $predicates = $this->compileCommonPredicates($criteria, $window);
        foreach ($this->compilePerSignalPredicates($criteria) as $predicate) {
            $predicates[] = $predicate;
        }

        $globs = $this->pruner->globsFor($tenantSlug, $this->signalSubdir(), $window);

        $result = $this->scanner->scan($globs, $predicates, $limit, $resumeFrom);

        return array_map($this->rowToResource(...), $result->rows);
    }

    abstract protected function signalSubdir(): string;

    /**
     * @param array<string, mixed> $criteria
     *
     * @return iterable<Predicate>
     */
    abstract protected function compilePerSignalPredicates(array $criteria): iterable;

    /**
     * @param array<string, mixed> $row
     */
    abstract protected function rowToResource(array $row): object;

    /**
     * Common predicates: time window + service/environment/host. Time
     * column differs by signal so this is also a hook (default
     * `time_unix_nano` works for logs and metrics; traces override).
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<Predicate>
     */
    protected function compileCommonPredicates(array $criteria, TimeWindow $window): array
    {
        $predicates = [];
        $predicates[] = new ColumnInRange($this->timeColumn(), $window->sinceUnixNano, $window->untilUnixNano);

        if (isset($criteria['service']) && \is_string($criteria['service']) && '' !== $criteria['service']) {
            $predicates[] = new ColumnEquals('resource_service_name', $criteria['service']);
        }
        if (isset($criteria['environment']) && \is_string($criteria['environment']) && '' !== $criteria['environment']) {
            $predicates[] = new ColumnEquals('resource_deployment_environment', $criteria['environment']);
        }
        if (isset($criteria['host']) && \is_string($criteria['host']) && '' !== $criteria['host']) {
            $predicates[] = new ColumnEquals('resource_host_name', $criteria['host']);
        }

        return $predicates;
    }

    /**
     * Time column the signal uses for partition pruning + ordering.
     * Logs and metrics: `time_unix_nano`. Traces: `start_time_unix_nano`.
     */
    protected function timeColumn(): string
    {
        return 'time_unix_nano';
    }

    /**
     * On-disk trace_id_hex / span_id_hex columns may be stored as raw
     * bytes (writer convention) or pre-hexed (test fixtures); helper
     * normalises to lowercase hex.
     */
    protected static function bytesToHex(?string $bytes): ?string
    {
        if (null === $bytes || '' === $bytes) {
            return null;
        }
        if (1 === preg_match('/^[0-9a-f]+$/', $bytes)) {
            return $bytes;
        }

        return bin2hex($bytes);
    }
}
