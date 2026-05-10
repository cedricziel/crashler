<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\AggregatingScanner;
use App\Read\Compute\AggregationCardinalityExceededException;
use App\Read\Compute\Aggregations\AccumulatorFactory;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnInRange;
use App\Read\Compute\Predicates\Predicate;
use App\Read\Criteria\TimeWindow;
use App\Security\IngestUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared scaffolding for `GET /v1/<signal>/aggregate` endpoints.
 *
 * v1 ships count/sum/avg/min/max with single-column groupBy. Time-bucket
 * intervals, percentiles (p50–p99), multi-column groupBy, and the
 * `_links.search` drill-down affordance are tracked under follow-up tasks.
 */
abstract readonly class AggregateController
{
    public function __construct(
        protected AggregatingScanner $scanner,
        protected PartitionPruner $pruner,
        protected Security $security,
        protected ClockInterface $clock,
        protected int $maxTimeWindowDays,
    ) {
    }

    public function handle(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof IngestUser) {
            return new JsonResponse(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }
        $tenantSlug = $user->tenant->slug;

        $function = (string) $request->query->get('function', '');
        if ('' === $function) {
            return new JsonResponse(['message' => \sprintf('`function` is required. Supported: %s.', implode(', ', AccumulatorFactory::SUPPORTED_FUNCTIONS))], Response::HTTP_BAD_REQUEST);
        }
        if (!\in_array($function, AccumulatorFactory::SUPPORTED_FUNCTIONS, true)) {
            return new JsonResponse(['message' => \sprintf('Unsupported `function` `%s`. Supported: %s.', $function, implode(', ', AccumulatorFactory::SUPPORTED_FUNCTIONS))], Response::HTTP_BAD_REQUEST);
        }

        $rawColumn = $request->query->get('column');
        $valueColumn = null;
        if (AccumulatorFactory::functionRequiresColumn($function)) {
            if (!\is_string($rawColumn) || '' === $rawColumn) {
                return new JsonResponse(['message' => \sprintf('`column` is required for function `%s`.', $function)], Response::HTTP_BAD_REQUEST);
            }
            $valueColumn = $this->valueColumnAliases()[$rawColumn] ?? $rawColumn;
            if (!\in_array($valueColumn, $this->allowedValueColumns(), true)) {
                return new JsonResponse(['message' => \sprintf('Unknown `column` `%s`. Allowed value columns: %s.', $rawColumn, implode(', ', $this->allowedValueColumns()))], Response::HTTP_BAD_REQUEST);
            }
        }

        $groupByRaw = $request->query->get('groupBy');
        $groupByColumn = null;
        if (\is_string($groupByRaw) && '' !== $groupByRaw) {
            if (str_contains($groupByRaw, ',')) {
                return new JsonResponse(['message' => 'Multi-column `groupBy` is not yet supported in v1; use a single typed column.'], Response::HTTP_BAD_REQUEST);
            }
            $groupByColumn = $this->groupByAliases()[$groupByRaw] ?? $groupByRaw;
            if (!\in_array($groupByColumn, $this->allowedGroupByColumns(), true)) {
                return new JsonResponse(['message' => \sprintf('Unknown `groupBy` column `%s`. Allowed: %s.', $groupByRaw, implode(', ', $this->allowedGroupByColumns()))], Response::HTTP_BAD_REQUEST);
            }
        }

        if (null !== $request->query->get('interval')) {
            return new JsonResponse(['message' => 'Time `interval` bucketing is not yet supported in v1.'], Response::HTTP_NOT_IMPLEMENTED);
        }

        try {
            $window = TimeWindow::parse(
                ['since' => $request->query->get('since'), 'until' => $request->query->get('until')],
                $this->clock,
                $this->maxTimeWindowDays,
            );
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $predicates = [new ColumnInRange($this->timeColumn(), $window->sinceUnixNano, $window->untilUnixNano)];
        foreach ($this->buildSignalPredicates($request) as $predicate) {
            $predicates[] = $predicate;
        }

        $globs = $this->pruner->globsFor($tenantSlug, $this->signalSubdir(), $window);

        try {
            $result = $this->scanner->aggregate($globs, $predicates, $function, $valueColumn, $groupByColumn);
        } catch (AggregationCardinalityExceededException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\App\Read\Compute\ScanTimeoutException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_GATEWAY_TIMEOUT);
        }

        return new JsonResponse([
            'function' => $function,
            'column' => $valueColumn,
            'groupBy' => $groupByColumn,
            'window' => [
                'since_unix_nano' => (string) $window->sinceUnixNano,
                'until_unix_nano' => (string) $window->untilUnixNano,
            ],
            'rows' => $result->rows,
        ]);
    }

    abstract protected function signalSubdir(): string;

    /** @return list<string> */
    abstract protected function allowedValueColumns(): array;

    /** @return list<string> */
    abstract protected function allowedGroupByColumns(): array;

    /** @return array<string, string> */
    protected function valueColumnAliases(): array
    {
        return [];
    }

    /** @return array<string, string> */
    protected function groupByAliases(): array
    {
        return [];
    }

    /** @return iterable<Predicate> */
    protected function buildSignalPredicates(Request $request): iterable
    {
        return [];
    }

    protected function timeColumn(): string
    {
        return 'time_unix_nano';
    }
}
