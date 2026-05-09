<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\InvalidPredicateTreeException;
use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Compute\Predicates\Predicate;
use App\Read\Compute\PredicateTreeCompiler;
use App\Read\Criteria\TimeWindow;
use App\Read\Cursor\CriteriaCanonicalizer;
use App\Read\Cursor\Cursor;
use App\Read\Cursor\InvalidCursorException;
use App\Read\Http\InvalidPostSearchBodyException;
use App\Read\Http\PostSearchRequestParser;
use App\Read\Http\PostSearchResponseShaper;
use App\Security\IngestUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared scaffolding for the per-signal `POST /v1/<signal>/search` endpoints.
 *
 * Owns: bearer/tenant resolution, body parsing, time-window resolution, limit
 * validation, cursor decode (with criteria-digest match), criteria
 * canonicalisation/digest computation, predicate-tree compile, scanner
 * dispatch, row → resource materialisation, content-negotiated response
 * shaping, and next-page cursor mint.
 *
 * Subclasses provide:
 *  - {@see signalSubdir}: 'logs' / 'traces' / 'metrics'
 *  - {@see compiler}: pre-configured {@see PredicateTreeCompiler} for the signal
 *  - {@see timeColumn}: 'time_unix_nano' for logs/metrics, 'start_time_unix_nano' for traces
 *  - {@see rowToResource}: row map → Resource DTO (delegates to the corresponding state provider)
 *  - {@see shortName} / {@see collectionPath}: response envelope identifiers
 */
abstract readonly class PostSearchController
{
    public function __construct(
        protected ParquetScanner $scanner,
        protected PartitionPruner $pruner,
        protected Security $security,
        protected ClockInterface $clock,
        protected PostSearchRequestParser $parser,
        protected PostSearchResponseShaper $shaper,
        protected int $maxTimeWindowDays,
        protected int $maxPageSize,
        protected string $cursorSecret,
    ) {
    }

    public function handle(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof IngestUser) {
            return new JsonResponse(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }
        $tenantSlug = $user->tenant->slug;

        try {
            $dto = $this->parser->parse($request);
        } catch (InvalidPostSearchBodyException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->statusCode);
        }

        $criteriaDigest = CriteriaCanonicalizer::digest($dto->criteria);

        $resumeFrom = null;
        $effectiveSince = $dto->since;
        $effectiveUntil = $dto->until;
        $effectiveLimit = $dto->limit;

        if (null !== $dto->cursor) {
            try {
                $cursor = Cursor::decode($dto->cursor, $this->cursorSecret, $tenantSlug, $this->maxTimeWindowDays);
            } catch (InvalidCursorException $e) {
                return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
            if (null === $cursor->criteriaDigest) {
                return new JsonResponse(['message' => 'Invalid cursor: minted by GET /v1/<signal> and cannot be replayed against POST search.'], Response::HTTP_BAD_REQUEST);
            }
            if (!hash_equals($cursor->criteriaDigest, $criteriaDigest)) {
                return new JsonResponse(['message' => 'Invalid cursor: criteria changed between calls. Cursors are bound to the criteria they were minted for.'], Response::HTTP_BAD_REQUEST);
            }
            $resumeFrom = $cursor->position;
            // Replay the absolute window/limit captured in the cursor so paging is stable.
            $effectiveSince = $cursor->criteria['since'] ?? $effectiveSince;
            $effectiveUntil = $cursor->criteria['until'] ?? $effectiveUntil;
            $effectiveLimit = $cursor->criteria['limit'] ?? $effectiveLimit;
        }

        try {
            $window = TimeWindow::parse(
                ['since' => $this->stringOrNull($effectiveSince), 'until' => $this->stringOrNull($effectiveUntil)],
                $this->clock,
                $this->maxTimeWindowDays,
            );
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $limit = \is_int($effectiveLimit) ? $effectiveLimit : 100;
        if ($limit < 1) {
            return new JsonResponse(['message' => '`limit` must be a positive integer.'], Response::HTTP_BAD_REQUEST);
        }
        if ($limit > $this->maxPageSize) {
            return new JsonResponse(['message' => \sprintf('`limit` exceeds max_page_size (%d).', $this->maxPageSize)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $bodyPredicates = $this->compiler()->compile($dto->criteria);
        } catch (InvalidPredicateTreeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $predicates = [
            new ColumnInRange($this->timeColumn(), $window->sinceUnixNano, $window->untilUnixNano),
            ...$bodyPredicates,
        ];

        // Reject any column-leaf that referenced a column outside the
        // common+per-signal whitelist. The compiler already guards, but
        // common columns (`resource_service_name` etc.) are added here
        // unconditionally if they appear; nothing extra to do.

        $globs = $this->pruner->globsFor($tenantSlug, $this->signalSubdir(), $window);
        try {
            $result = $this->scanner->scan($globs, $predicates, $limit, $resumeFrom);
        } catch (\App\Read\Compute\ScanTimeoutException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_GATEWAY_TIMEOUT);
        } catch (\App\Read\Compute\ScanIoException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $resources = array_map($this->rowToResource(...), $result->rows);

        $nextCursor = null;
        if ($result->hasMore && null !== $result->position) {
            $criteriaForCursor = [
                'since' => (string) $window->sinceUnixNano,
                'until' => (string) $window->untilUnixNano,
                'limit' => $limit,
            ];
            $nextCursor = Cursor::mint(
                criteria: $criteriaForCursor,
                position: $result->position,
                tenantSlug: $tenantSlug,
                secret: $this->cursorSecret,
                criteriaDigest: $criteriaDigest,
            );
        }

        $response = $this->shaper->shape(
            request: $request,
            resources: $resources,
            shortName: $this->shortName(),
            collectionPath: $this->collectionPath(),
            cursor: $nextCursor,
            signal: $this->signalSubdir(),
        );

        return $response;
    }

    abstract protected function signalSubdir(): string;

    abstract protected function compiler(): PredicateTreeCompiler;

    abstract protected function shortName(): string;

    abstract protected function collectionPath(): string;

    /**
     * @param array<string, mixed> $row
     */
    abstract protected function rowToResource(array $row): object;

    protected function timeColumn(): string
    {
        return 'time_unix_nano';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_string($value)) {
            return '' === $value ? null : $value;
        }

        return null;
    }
}
