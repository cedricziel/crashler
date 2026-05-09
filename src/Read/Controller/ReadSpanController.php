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
 * GET /v1/spans/{spanId} — returns one span by its 16-byte ID.
 *
 * Per design D7 alternative ③, plain Symfony controller (not API Platform)
 * because the response shape is OTLP `Span`-faithful, which doesn't fit
 * AP's "single Resource" model cleanly. Same firewall + Bearer auth.
 */
final class ReadSpanController
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

    #[Route(path: '/v1/spans/{spanId}', name: 'crashler_read_span', methods: ['GET'], requirements: ['spanId' => '[0-9a-f]{16}'])]
    public function __invoke(string $spanId, Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof IngestUser) {
            return new JsonResponse(['message' => 'Unauthorized.'], 401);
        }
        $tenantSlug = $user->tenant->slug;

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
        $predicates = [new ColumnEquals('span_id_hex', $spanId)];

        $result = $this->scanner->scan($globs, $predicates, limit: 1);

        if ([] === $result->rows) {
            return new JsonResponse([
                'message' => \sprintf(
                    'Span %s not found within the searched window. Pass explicit since/until to widen the lookup.',
                    $spanId,
                ),
            ], 404);
        }

        $row = $result->rows[0];
        $traceId = self::bytesToHex($row['trace_id_hex'] ?? '');
        $spanStart = isset($row['start_time_unix_nano']) ? (int) $row['start_time_unix_nano'] : 0;
        $spanEnd = isset($row['end_time_unix_nano']) ? (int) $row['end_time_unix_nano'] : 0;

        $span = TraceTreeAssembler::rowToSpan($row);

        return new JsonResponse([
            'span' => $span,
            '_links' => [
                'self' => "/v1/spans/{$spanId}",
                'trace' => "/v1/traces/{$traceId}",
                'logs' => \sprintf('/v1/logs?traceId=%s&spanId=%s&since=%d&until=%d', $traceId, $spanId, $spanStart, $spanEnd),
            ],
        ]);
    }

    private static function bytesToHex(?string $bytes): string
    {
        if (null === $bytes || '' === $bytes) {
            return '';
        }
        if (1 === preg_match('/^[0-9a-f]+$/', $bytes)) {
            return $bytes;
        }

        return bin2hex($bytes);
    }
}
