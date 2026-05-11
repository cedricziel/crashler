<?php

declare(strict_types=1);

namespace App\Controller;

use App\Explorer\TraceWaterfallResolver;
use App\Read\Criteria\TimeWindow;
use App\Repository\TenantRepository;
use App\Security\Voter\TenantVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Trace waterfall view at /tenants/{slug}/traces/{traceId}.
 *
 * 80/20 horizontal split: indented span tree on the left, deferred
 * Live sidebar on the right that lazy-loads the clicked span's
 * attributes / status / events / resource.
 *
 * Span tree is rendered server-side (depth-first, capped at MAX_SPANS=500
 * with a "+N more" tail when truncated). The sidebar is a Live Component
 * so clicking a span updates only the sidebar — no full page reload.
 */
final class WaterfallController extends AbstractController
{
    public function __construct(
        private readonly TraceWaterfallResolver $resolver,
        private readonly ClockInterface $clock,
        private readonly int $maxTimeWindowDays,
    ) {
    }

    #[Route(
        path: '/tenants/{slug}/traces/{traceId}',
        name: 'app_trace_waterfall',
        requirements: ['traceId' => '[0-9a-f]{32}'],
        methods: ['GET'],
    )]
    public function index(
        string $slug,
        string $traceId,
        Request $request,
        TenantRepository $tenants,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);

        // When the link came from the explorer table the URL carries the
        // explorer's `since` / `until` (typically unix-nano integers); use
        // them so a trace outside the default 24h lookback still resolves.
        // No hint → fall back to the 24h `span_lookup_window_hours`
        // contract the read API's /v1/traces/{id} endpoint uses.
        $since = self::nullIfBlank($request->query->get('since'));
        $until = self::nullIfBlank($request->query->get('until'));
        try {
            $window = TimeWindow::parse(
                null === $since && null === $until
                    ? ['since' => $this->resolver->spanLookupWindowHours().'h']
                    : ['since' => $since, 'until' => $until],
                $this->clock,
                $this->maxTimeWindowDays,
            );
        } catch (\InvalidArgumentException|\OutOfRangeException) {
            throw new NotFoundHttpException();
        }

        $trace = $this->resolver->resolve($tenant->getSlug(), $traceId, $window);
        if (null === $trace) {
            throw new NotFoundHttpException(\sprintf(
                'Trace %s not found within the requested window. Widen `since`/`until` or adjust span_lookup_window_hours if traces are older than %d hours.',
                $traceId,
                $this->resolver->spanLookupWindowHours(),
            ));
        }

        return $this->render('waterfall/index.html.twig', [
            'tenant' => $tenant,
            'trace' => $trace,
        ]);
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        return '' === trim($value) ? null : $value;
    }
}
