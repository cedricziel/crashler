<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Criteria\TimeWindow;
use App\Security\IngestUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /v1/traces/{traceId} — returns the full span tree for one trace.
 *
 * Why not API Platform? AP's Item operation contract assumes "one Resource
 * → one normalized object". The OTLP `ResourceSpans` shape is a tree-of-
 * trees that doesn't fit a single Resource without inventing synthetic
 * envelope DTOs. Per design D7 alternative ③, this lives as a plain
 * Symfony controller — same firewall, same Bearer auth, same tenant model.
 * The trade-off (acknowledged in design.md): the OpenAPI spec at /docs.json
 * doesn't auto-document this endpoint. The README's "Reading data" section
 * documents it explicitly.
 *
 * Response shape:
 *   {
 *     "resourceSpans": [
 *       {
 *         "resource": {"attributes": [{"key":..., "value":...}]},
 *         "scopeSpans": [
 *           {
 *             "scope": {"name":..., "version":...},
 *             "spans": [{ <OTLP Span fields, traceId/spanId as lowercase hex> }]
 *           }
 *         ]
 *       }
 *     ],
 *     "_links": {
 *       "self": "/v1/traces/<id>",
 *       "logs": "/v1/logs?traceId=<id>&since=<start>&until=<end>",
 *       "metricsWithExemplars": "/v1/metrics?exemplarTraceId=<id>&since=<start>&until=<end>"
 *     }
 *   }
 */
final class ReadTraceController
{
    public function __construct(
        private readonly ParquetScanner $scanner,
        private readonly PartitionPruner $pruner,
        private readonly Security $security,
        private readonly ClockInterface $clock,
        private readonly int $maxTimeWindowDays,
        private readonly int $spanLookupWindowHours,
    ) {
    }

    #[Route(path: '/v1/traces/{traceId}', name: 'crashler_read_trace', methods: ['GET'], requirements: ['traceId' => '[0-9a-f]{32}'])]
    public function __invoke(string $traceId, Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof IngestUser) {
            return new JsonResponse(['message' => 'Unauthorized.'], 401);
        }
        $tenantSlug = $user->tenant->slug;

        // Default lookup window is the last `span_lookup_window_hours`
        // (default 24h). Operators can override with explicit since/until.
        $rawSince = $request->query->get('since');
        $rawUntil = $request->query->get('until');
        try {
            if (null === $rawSince && null === $rawUntil) {
                $window = TimeWindow::parse(['since' => $this->spanLookupWindowHours.'h'], $this->clock, $this->maxTimeWindowDays);
            } else {
                $window = TimeWindow::parse(['since' => $rawSince, 'until' => $rawUntil], $this->clock, $this->maxTimeWindowDays);
            }
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }

        $globs = $this->pruner->globsFor($tenantSlug, 'traces', $window);
        $predicates = [
            new ColumnEquals('trace_id_hex', $traceId),
        ];

        $result = $this->scanner->scan($globs, $predicates, limit: 1000);

        if ([] === $result->rows) {
            return new JsonResponse([
                'message' => \sprintf(
                    'Trace %s not found within the searched window [%d..%d]. Pass explicit since/until to widen the lookup.',
                    $traceId,
                    $window->sinceUnixNano,
                    $window->untilUnixNano,
                ),
            ], 404);
        }

        // Extract the trace's actual time bounds for the cross-signal links.
        $traceStart = (int) min(array_column($result->rows, 'start_time_unix_nano'));
        $traceEnd = (int) max(array_column($result->rows, 'end_time_unix_nano'));

        $resourceSpans = TraceTreeAssembler::assemble($result->rows);

        return new JsonResponse([
            'resourceSpans' => $resourceSpans,
            '_links' => [
                'self' => "/v1/traces/{$traceId}",
                'logs' => \sprintf('/v1/logs?traceId=%s&since=%d&until=%d', $traceId, $traceStart, $traceEnd),
                'metricsWithExemplars' => \sprintf('/v1/metrics?exemplarTraceId=%s&since=%d&until=%d', $traceId, $traceStart, $traceEnd),
            ],
        ]);
    }
}
